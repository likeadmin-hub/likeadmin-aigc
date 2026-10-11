<?php
namespace app\common\service\power;

use app\common\model\ai\AiAppTask;
use app\common\model\ai\AiConsumptionLog;
use app\common\model\app\aigc_music\AigcMusicTask;
use app\common\model\app\aigc_music\AigcMusicBilling;
use app\common\model\power\PowerMarketProduct;
use app\common\service\app\AppAccessService;
use app\common\service\ai\AiTaskJobService;
use app\common\service\point\PointService;
use app\common\service\update\UpdateSourceClient;
use app\common\service\open_platform\{OpenStore,OpenVault,OpenException,OpenCallContext,OpenRecovery,OpenTransport,OpenGateway};
use think\facade\Db;

/** Shared real music text operations. Fixed upstream routes and immutable native receipts. */
final class MarketMusicOperationRuntimeService
{
    public const ROUTES=['lyrics'=>'lyrics/index','mashup_lyrics'=>'mashuplyrics/index','style'=>'style/index'];

    public static function input(string $api,array $params): array
    {
        if(!isset(self::ROUTES[$api]))throw new OpenException('capability_unavailable','音乐操作尚未接入',404);
        $fields=$api==='mashup_lyrics'?['lyrics_a','lyrics_b']:['prompt'];$payload=[];
        foreach($fields as $field){$aliases=['prompt'=>'theme','lyrics_a'=>'source_lyrics','lyrics_b'=>'target_lyrics'];$value=$params[$field]??$params[$aliases[$field]]??'';if(!is_string($value))throw new OpenException('invalid_request',$field.' 必须是文本');$text=trim($value);if($text===''||mb_strlen($text)>20000)throw new OpenException('invalid_request','请提供有效的 '.$field);$payload[$field]=$text;}
        return $payload;
    }
    public static function quote(int $tenant,int $user,string $api,array $params): array
    {
        self::input($api,$params);$denied=AppAccessService::assertTenantCanUse($tenant,'aigc_music',$user,true);if($denied)throw new OpenException('permission_denied','音乐应用未开通或用户资格不足',403);
        $id=(int)PowerMarketProduct::where(['resource_type'=>'app_api','upstream_app_code'=>'music_generation','upstream_api_code'=>$api,'status'=>1])->order('id','desc')->value('id');
        $product=TenantPowerMarketService::detail($tenant,$id);$candidates=[];
        foreach($product['skus']??[] as $sku)if(!empty($sku['sale_status'])&&$sku['usage_unit']==='per_call'&&(float)$sku['usage_unit_size']===1.&&OpenStore::json($sku['locked_params'])===[])$candidates[]=$sku;
        if(count($candidates)!==1)throw new OpenException('pricing_unavailable','音乐操作缺少唯一的可售按次 SKU',503);
        $sku=$candidates[0];$cost=round((float)$sku['platform_sale_points'],2);$price=round((float)$sku['sale_points'],2);
        if(!is_finite($cost)||!is_finite($price)||$cost<0||$price<0)throw new OpenException('pricing_unavailable','音乐价格无效',503);
        return ['market_product_id'=>$id,'market_sku_id'=>(int)$sku['id'],'sku_key'=>$sku['sku_key'],'api_code'=>$api,'billing_unit'=>'per_call','quantity'=>1,'tenant_cost_points'=>$cost,'user_charge_points'=>$price,'platform_unit_cost'=>$cost,'tenant_unit_price'=>$price,'price_source'=>'power_market_sku'];
    }
    public static function budget(int $tenant,int $user,string $api,array $params): array
    {
        $quote=self::quote($tenant,$user,$api,$params);$selection=['tenant_id'=>$tenant,'user_id'=>$user,'api_code'=>$api,'payload'=>self::input($api,$params),'quote'=>$quote];
        return ['user'=>$quote['user_charge_points'],'tenant'=>$quote['tenant_cost_points'],'snapshot'=>['quote'=>$quote,'billing_owner'=>'tool','native_music_operation_blob'=>OpenVault::seal(OpenStore::encode($selection))]];
    }
    public static function selection(int $tenant,int $user,string $api,array $params): array
    {
        $payload=self::input($api,$params);$rid=OpenCallContext::id();
        if($rid==='')return ['tenant_id'=>$tenant,'user_id'=>$user,'api_code'=>$api,'payload'=>$payload,'quote'=>self::quote($tenant,$user,$api,$payload)];
        $row=OpenStore::owner('request',$tenant,$user)->where(['request_id'=>$rid,'kind'=>'tool','app_code'=>'aigc_music','method'=>'POST','path'=>'/api/v1/tools/aigc_music/'.self::ROUTES[$api]])->find();
        if(!$row)throw new OpenException('task_context_invalid','音乐操作归属不一致',409);OpenCallContext::assertLease($row);
        $blob=OpenStore::json($row['snapshot'])['native_music_operation_blob']??'';if($blob==='')throw new OpenException('pricing_snapshot_invalid','音乐操作缺少受理快照',409);$selection=OpenStore::json(OpenVault::open($blob));
        if((int)($selection['tenant_id']??0)!==$tenant||(int)($selection['user_id']??0)!==$user||($selection['api_code']??'')!==$api||($selection['payload']??null)!==$payload)throw new OpenException('pricing_snapshot_conflict','音乐操作参数与受理快照不一致',409);
        return $selection;
    }
    public static function execute(int $tenant,int $user,string $api,array $params): array
    {
        $selection=self::selection($tenant,$user,$api,$params);$cid=self::reserve($tenant,$user,$api,$selection['payload'],$selection);
        // Durable queue may recover a stored receipt, never repeat a submit.
        AiTaskJobService::enqueueQueryResult($cid);
        $c=AiConsumptionLog::findOrEmpty($cid);$snapshot=OpenStore::json($c['price_snapshot']);
        Db::transaction(static function()use($cid){$c=AiConsumptionLog::lock(true)->findOrEmpty($cid);if($c['run_status']!=='reserved')throw new OpenException('request_in_progress','音乐操作已开始',409);self::assertOrigin($c->toArray());$snapshot=OpenStore::json($c['price_snapshot']);$snapshot['music_dispatched']=true;$c->save(['price_snapshot'=>$snapshot,'run_status'=>'submitting','update_time'=>time()]);});
        try{
            $wire=(new OpenTransport())->send(OpenStore::json(OpenVault::open($snapshot['music_source_blob'])),'POST','/api/v1/apps/music_generation/'.$api,$selection['payload']);
            self::capture($cid,$wire);self::refresh($cid);
        }catch(\Throwable $e){if(OpenRecovery::connectionLost($e))throw $e;$latest=AiConsumptionLog::findOrEmpty($cid);if(!in_array($latest['billing_status'],['settled','refunded'],true))self::uncertain($cid);throw $e;}
        $native=AigcMusicTask::where('consumption_id',$cid)->findOrEmpty();return self::taskData($native->toArray());
    }
    private static function reserve(int $tenant,int $user,string $api,array $payload,array $selection): int
    {
        $source=UpdateSourceClient::getSource();$source=['base_url'=>$source['active_base_url'],'api_key'=>$source['active_api_key'],'ssl_verify'=>UpdateSourceClient::sslVerify($source)];if(empty($source['base_url'])||empty($source['api_key']))throw new OpenException('source_unavailable','音乐上游未配置',503);
        return Db::transaction(static function()use($tenant,$user,$api,$payload,$selection,$source){
            $quote=$selection['quote'];$now=time();$sn='MO'.date('YmdHis').strtoupper(bin2hex(random_bytes(8)));$rid=OpenCallContext::id();
            if($rid!==''){$row=OpenStore::owner('request',$tenant,$user)->where('request_id',$rid)->lock(true)->find();if(!$row||!$row['active']||$row['tool_task_id']!=='')throw new OpenException('task_context_invalid','音乐请求已经受理或关闭',409);OpenCallContext::assertLease($row);}
            $native=AigcMusicTask::create(['tenant_id'=>$tenant,'user_id'=>$user,'app_code'=>'aigc_music','action'=>$api,'title'=>'','prompt'=>$payload['prompt']??'','lyrics'=>'','provider'=>'market_music_operation','model'=>'music_generation','channel'=>'music_generation:'.$api,'quality'=>'per_call','ratio'=>'','quantity'=>1,'tenant_cost_points'=>$quote['tenant_cost_points'],'user_charge_points'=>$quote['user_charge_points'],'market_product_id'=>$quote['market_product_id'],'market_sku_id'=>$quote['market_sku_id'],'status'=>'running','billing_status'=>'reserved','idempotency_key'=>$rid?:$sn,'delete_time'=>0]+OpenStore::timestamp());
            $task=AiAppTask::create(['task_no'=>$sn,'tenant_id'=>$tenant,'user_id'=>$user,'app_code'=>'aigc_music','action_code'=>$api,'business_table'=>'aigc_music_task','business_id'=>$native['id'],'status'=>'submitting','progress'=>0,'request_summary'=>['api_code'=>$api],'result_summary'=>[],'estimated_tenant_cost'=>$quote['tenant_cost_points'],'estimated_user_price'=>$quote['user_charge_points'],'idempotency_key'=>$rid?:$sn]+OpenStore::timestamp());
            $snapshot=$quote+['app_code'=>'music_generation','runtime_adapter'=>'music_operation','music_source_blob'=>OpenVault::seal(OpenStore::encode($source))];
            $c=AiConsumptionLog::create(['consume_no'=>$sn,'app_task_id'=>$task['id'],'tenant_id'=>$tenant,'user_id'=>$user,'app_code'=>'aigc_music','action_code'=>$api,'resource_type'=>'app_api','product_id'=>$quote['market_product_id'],'sku_id'=>$quote['market_sku_id'],'protocol'=>'application_api','provider'=>'power_market','quantity'=>1,'usage_unit'=>'per_call','price_snapshot'=>$snapshot,'usage_snapshot'=>[],'request_summary'=>['api_code'=>$api],'response_summary'=>[],'run_status'=>'reserved','billing_status'=>'reserved','reserved_tenant_cost'=>$quote['tenant_cost_points'],'reserved_user_price'=>$quote['user_charge_points'],'tenant_point_sn'=>$sn.'-reserve','user_point_sn'=>$sn.'-reserve']+OpenStore::timestamp());
            PointService::reserveBusinessAmountsInCurrentTransaction($tenant,$user,$quote['tenant_cost_points'],$quote['user_charge_points'],$sn.'-reserve','音乐操作预占',['app_code'=>'aigc_music','consumption_id'=>$c['id']]);
            $native->save(['app_task_id'=>$task['id'],'consumption_id'=>$c['id']]);return (int)$c['id'];
        });
    }
    private static function capture(int $cid,array $wire): void
    {
        Db::transaction(static function()use($cid,$wire){$c=AiConsumptionLog::lock(true)->findOrEmpty($cid);self::assertOrigin($c->toArray());$snapshot=OpenStore::json($c['price_snapshot']);$snapshot['music_receipt_blob']=OpenVault::seal(OpenStore::encode(['http_status'=>$wire['status'],'body'=>OpenStore::json($wire['body'])]));$c->save(['price_snapshot'=>$snapshot,'run_status'=>'running','update_time'=>time()]);});
    }
    public static function refresh(int $cid): array
    {
        $c=AiConsumptionLog::findOrEmpty($cid);if($c->isEmpty())throw new OpenException('not_found','音乐消耗记录不存在',404);$snapshot=OpenStore::json($c['price_snapshot']);if(($snapshot['runtime_adapter']??'')!=='music_operation')throw new OpenException('task_context_invalid','音乐运行时不一致',409);
        if(in_array($c['billing_status'],['settled','refunded'],true))return ['status'=>$c['run_status']];
        $blob=$snapshot['music_receipt_blob']??'';if($blob==='')return ['status'=>$c['run_status']==='reserved'?'running':'pending_confirmation'];
        $wire=OpenStore::json(OpenVault::open($blob));$body=$wire['body']??$wire;$http=(int)($wire['http_status']??200);
        $failed=OpenGateway::wireFailed($http,$body,[]);$message=(string)($body['msg']??$body['message']??'');$ambiguous=OpenGateway::wireUncertain($http)||(bool)preg_match('/timeout|timed out|connection|curl|超时|连接|网络|内部异常/iu',$message);
        if($ambiguous){self::uncertain($cid);return ['status'=>'pending_confirmation'];}
        if($failed){self::complete($cid,[],true);return ['status'=>'failed'];}
        $result=(array)($body['data']['result']??[]);$data=$result['data']??[];if(is_array($data)&&array_is_list($data))$data=$data[0]??[];
        $field=$snapshot['api_code']==='style'?'upsampled_tags':'text';$text=is_array($data)?($data[$field]??''):'';
        if(!in_array($result['status']??'',['completed','complete','success','succeeded'],true)||!is_string($text)||trim($text)===''){self::uncertain($cid);return ['status'=>'pending_confirmation'];}
        $public=[$field==='text'?'lyrics':'style'=>$text,'title'=>(string)($data['title']??$result['title']??'')];self::complete($cid,$public,false);return ['status'=>'success']+$public;
    }
    private static function uncertain(int $cid): void
    {
        Db::transaction(static function()use($cid){$c=AiConsumptionLog::lock(true)->findOrEmpty($cid);if(in_array($c['billing_status'],['settled','refunded'],true))return;self::assertOrigin($c->toArray());$snapshot=OpenStore::json($c['price_snapshot']);$known=!empty($snapshot['music_receipt_blob']);$c->save(['run_status'=>$known?'running':'pending_confirmation','billing_status'=>'reserved','error_code'=>'pending_confirmation','update_time'=>time()]);AigcMusicTask::where('consumption_id',$cid)->update(['status'=>'pending_confirmation','billing_status'=>'reserved','error_code'=>'pending_confirmation','update_time'=>time()]);});
    }
    private static function complete(int $cid,array $result,bool $failed): void
    {
        Db::transaction(static function()use($cid,$result,$failed){
            $c=AiConsumptionLog::lock(true)->findOrEmpty($cid);if(in_array($c['billing_status'],['settled','refunded'],true))return;self::assertOrigin($c->toArray());$snapshot=OpenStore::json($c['price_snapshot']);$user=$failed?0:(float)$snapshot['user_charge_points'];$tenant=$failed?0:(float)$snapshot['tenant_cost_points'];$sn=(string)$c['consume_no'];$now=time();
            PointService::settleReservedBusinessAmountsInCurrentTransaction((int)$c['tenant_id'],(int)$c['user_id'],(float)$c['reserved_tenant_cost'],(float)$c['reserved_user_price'],$tenant,$user,$sn,'音乐操作结算',['app_code'=>'aigc_music','consumption_id'=>$cid]);
            $state=$failed?'failed':'success';$billing=$failed?'refunded':'settled';$native=AigcMusicTask::where(['consumption_id'=>$cid,'tenant_id'=>$c['tenant_id'],'user_id'=>$c['user_id']])->lock(true)->findOrEmpty();if($native->isEmpty())throw new OpenException('task_context_invalid','音乐业务任务关联缺失',409);
            $native->save(['status'=>$state,'billing_status'=>$billing,'progress'=>100,'lyrics'=>$result['lyrics']??'','title'=>$result['title']??'','result_json'=>$result,'finish_time'=>$now,'update_time'=>$now]);
            AigcMusicBilling::create(['tenant_id'=>$c['tenant_id'],'user_id'=>$c['user_id'],'task_id'=>$native['id'],'channel'=>'music_generation:'.$snapshot['api_code'],'quality'=>'per_call','quantity'=>1,'platform_unit_cost'=>$snapshot['tenant_cost_points'],'tenant_unit_price'=>$snapshot['user_charge_points'],'tenant_cost_points'=>$tenant,'user_charge_points'=>$user,'billing_status'=>$billing,'tenant_point_sn'=>$sn.'-reserve','user_point_sn'=>$sn.'-reserve','refund_time'=>$failed?$now:0]+OpenStore::timestamp());
            $c->save(['run_status'=>$state,'billing_status'=>$billing,'actual_tenant_cost'=>$tenant,'actual_user_price'=>$user,'quantity'=>$failed?0:1,'usage_snapshot'=>['calls'=>$failed?0:1],'response_summary'=>['has_result'=>!$failed],'finish_time'=>$now,'update_time'=>$now]);AiAppTask::where('id',$c['app_task_id'])->update(['status'=>$state,'progress'=>100,'actual_tenant_cost'=>$tenant,'actual_user_price'=>$user,'result_summary'=>['has_result'=>!$failed],'finish_time'=>$now,'update_time'=>$now]);
        });
    }
    private static function assertOrigin(array $c): void
    {
        $rid=OpenStore::json($c['price_snapshot']??[])['_open_platform_request_id']??'';if($rid==='')return;
        $row=OpenStore::owner('request',(int)$c['tenant_id'],(int)$c['user_id'])->where('request_id',$rid)->lock(true)->find();if(!$row)throw new OpenException('task_context_invalid','音乐请求归属缺失',409);OpenCallContext::assertLease($row);
    }
    public static function syncNative(array $consumption,int $taskId): void
    {
        $native=AigcMusicTask::where(['id'=>$taskId,'tenant_id'=>$consumption['tenant_id'],'user_id'=>$consumption['user_id'],'consumption_id'=>$consumption['id'],'provider'=>'market_music_operation'])->findOrEmpty();
        if($native->isEmpty())throw new OpenException('task_context_invalid','音乐业务任务归属不一致',409);
        self::refresh((int)$consumption['id']);
    }
    public static function taskData(array $task): array {return ['task_id'=>$task['id'],'status'=>$task['status'],'billing_status'=>$task['billing_status']]+OpenStore::json($task['result_json']??[]);}
    public static function cancel(int $cid): void {throw new OpenException('cancel_unavailable','音乐操作不支持取消已提交请求',409);}
    public static function fail(int $cid,string $message,string $code='failed'): void {throw new OpenException('pending_confirmation','音乐操作需要核实受理结果后处理',409);}
}
