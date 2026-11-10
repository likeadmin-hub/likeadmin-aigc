<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_motion_sync;

use app\common\service\power\{MarketMotionSyncRuntimeService as Motion,MarketVideoRuntimeService as Video};
use app\common\service\ai\{AiTaskJobService as Queue,AiTaskResultAssetService,MarketVideoSubmissionService};
use app\common\model\ai\{AiConsumptionLog,AiTaskResultAsset};
use app\common\service\SubmitLockService;
use InvalidArgumentException;
use think\facade\Db;

final class MotionSyncJobs
{
    public static function table(){return MotionSyncService::table('task');}
    public static function owned(int $tenant,int $user,int $id,bool $admin=false,bool $lock=false): array
    {
        $q=self::table()->where(['id'=>$id,'delete_time'=>0]);if(!$admin||$tenant>0)$q->where('tenant_id',$tenant);if(!$admin)$q->where('user_id',$user);if($lock)$q->lock(true);
        $r=$q->find();if(!$r)throw new InvalidArgumentException('创作任务不存在或无权访问');return $r;
    }
    private static function prepare(int $tenant,int $user,array $p): array
    {
        MotionSyncService::available($tenant,$user);$draft=MotionSyncService::owned($tenant,$user,(int)($p['project_id']??0));
        if((int)$draft['revision']!==(int)($p['revision']??0))throw new InvalidArgumentException('草稿已更新，请重新报价');
        $body=MotionSyncService::validateMedia($tenant,$user,MotionSyncService::body(MotionSyncService::json($draft['body_json'])));
        $request=MotionSyncService::request($tenant,$body);
        $quote=MotionSyncService::measured($body,static fn()=>Motion::quote($tenant,$request));
        return [$draft,$body,$request,$quote];
    }
    private static function priceKey(array $quote): string
    {return hash('sha256',MotionSyncService::encode($quote));}
    public static function estimate(int $tenant,int $user,array $p): array
    {
        [$draft,$body,$request,$quote]=self::prepare($tenant,$user,$p);$token=bin2hex(random_bytes(24));
        MotionSyncService::table('quote')->insert(['tenant_id'=>$tenant,'user_id'=>$user,'project_id'=>$draft['id'],'revision'=>$draft['revision'],'token'=>$token,
            'quote_json'=>MotionSyncService::encode($quote),'expires_at'=>time()+180,'create_time'=>time()]);
        return array_intersect_key($quote,array_flip(['user_charge_points','user_unit_points','quantity','billing_unit','billing_unit_size']))
            +['token'=>$token,'expires_at'=>time()+180,'notice'=>'按实测动作视频预估冻结，完成后按实际成片结算，多退少补'];
    }
    public static function create(int $tenant,int $user,array $p): array
    {
        MotionSyncService::available($tenant,$user);$key=MotionSyncService::text($p['idempotency_key']??'',100);
        if(!preg_match('/^[a-zA-Z0-9_-]{8,100}$/D',$key))throw new InvalidArgumentException('提交标识无效，请刷新后重试');
        $lock=SubmitLockService::acquire('motion_sync_submit_'.$key,$tenant,$user);
        try{
            $old=self::table()->where(['tenant_id'=>$tenant,'user_id'=>$user,'idempotency_key'=>$key])->find();
            if($old){if((int)$old['project_id']!==(int)($p['project_id']??0)||(int)$old['project_revision']!==(int)($p['revision']??0))throw new InvalidArgumentException('提交标识已用于其他草稿');return self::dto($old);}
            [$draft,$body,$request,$quote]=self::prepare($tenant,$user,$p);
            return Db::transaction(static function()use($tenant,$user,$p,$key,$draft,$body,$request,$quote){
                $current=MotionSyncService::owned($tenant,$user,(int)$draft['id'],true);
                if((int)$current['revision']!==(int)$draft['revision'])throw new InvalidArgumentException('草稿已更新，请重新报价');
                $q=MotionSyncService::table('quote')->where(['tenant_id'=>$tenant,'user_id'=>$user,'token'=>MotionSyncService::text($p['quote_token']??'',48)])->lock(true)->find();
                if(!$q||(int)$q['expires_at']<time()||(int)$q['project_id']!==(int)$draft['id']||(int)$q['revision']!==(int)$draft['revision']||!empty($q['consumed_task_id']))throw new InvalidArgumentException('报价已失效，请重新报价');
                if(self::priceKey(MotionSyncService::json($q['quote_json']))!==self::priceKey($quote))throw new InvalidArgumentException('模型或价格已更新，请重新报价');
                $id=self::table()->insertGetId(['tenant_id'=>$tenant,'user_id'=>$user,'project_id'=>$draft['id'],'project_revision'=>$draft['revision'],
                    'idempotency_key'=>$key,'status'=>'queued','prompt'=>$body['prompt'],'request_json'=>MotionSyncService::encode(['body'=>$body,'request'=>$request,'quote'=>$quote]),
                    'create_time'=>time(),'update_time'=>time()]);
                $request['idempotency_key']=$key;
                $reservation=MotionSyncService::measured($body,static fn()=>Video::reserve($tenant,$user,MotionSyncService::APP,'video','aigc_motion_sync_task',(string)$id,$request,$request));
                $frozen=AiConsumptionLog::find((int)$reservation['consumption_id']);
                $snapshot=(array)$frozen['price_snapshot'];$priced=(array)$quote['market_snapshot'];
                if(abs((float)$frozen['reserved_user_price']-(float)$quote['user_charge_points'])>0.000001
                    ||abs((float)$frozen['reserved_tenant_cost']-(float)$quote['tenant_cost_points'])>0.000001
                    ||(int)($snapshot['sku_id']??0)!==(int)$quote['market_sku_id']
                    ||($snapshot['locked_params']??[])!==($priced['locked_params']??[]))throw new InvalidArgumentException('模型或价格已更新，请重新报价');
                Video::linkBusinessTask((int)$reservation['app_task_id'],$id);
                self::table()->where('id',$id)->update(['app_task_id'=>$reservation['app_task_id'],'consumption_id'=>$reservation['consumption_id'],'billing_status'=>'reserved','user_charge_points'=>$quote['user_charge_points']]);
                MotionSyncService::table('quote')->where('id',$q['id'])->update(['consumed_task_id'=>$id]);
                Queue::enqueue(Queue::TYPE_MOTION_SYNC_SUBMIT,(int)$reservation['consumption_id']);
                return self::dto(self::owned($tenant,$user,$id));
            });
        }finally{SubmitLockService::release($lock);}
    }
    /** A consumed start marker is never reset, even when a worker dies before a receipt. */
    public static function run(int $consumptionId): bool
    {
        $c=AiConsumptionLog::find($consumptionId);if(!$c||$c['app_code']!==MotionSyncService::APP)return true;
        $lock=SubmitLockService::tryAcquire('motion_sync_worker_'.$consumptionId,(int)$c['tenant_id'],(int)$c['user_id']);if(!$lock)return false;
        try{
            $job=self::table()->where(['consumption_id'=>$consumptionId,'tenant_id'=>$c['tenant_id'],'user_id'=>$c['user_id']])->find();if(!$job)return true;
            if($job['status']!=='queued'||(int)$job['submit_started_at']>0){
                if($c['run_status']==='reserved'&&$job['submit_started_at']>0)self::table()->where('id',$job['id'])->update(['status'=>'unknown','update_time'=>time()]);
                else self::syncConsumption($c->toArray(),(int)$job['id']);
                return true;
            }
            $saved=MotionSyncService::json($job['request_json']);
            try{$body=MotionSyncService::validateMedia((int)$job['tenant_id'],(int)$job['user_id'],MotionSyncService::body($saved['body']));}
            catch(\Throwable $e){Video::fail($consumptionId,'提交前素材已失效','invalid_material');self::syncConsumption(AiConsumptionLog::find($consumptionId)->toArray(),(int)$job['id']);return true;}
            $started=self::table()->where(['id'=>$job['id'],'status'=>'queued','submit_started_at'=>0])->update(['status'=>'running','submit_started_at'=>time(),'update_time'=>time()]);
            if(!$started)return true;
            // CLI workers may have no request host. Keep the server-generated submit URL for local bytes.
            foreach(['image','video'] as $kind)if(($body[$kind]['storage_engine']??'')==='local'&&!preg_match('~^https?://~i',(string)$body[$kind]['url'])){
                $frozen=$saved['body'][$kind]??[];
                if(($frozen['file_id']??0)!==($body[$kind]['file_id']??0)||!preg_match('~^https?://~i',(string)($frozen['url']??'')))throw new \RuntimeException('本地素材缺少可公开访问的提交地址');
                $body[$kind]['url']=$frozen['url'];
            }
            $request=$saved['request'];$request['reference_assets']=[];
            foreach(['image','video'] as $kind)$request['reference_assets'][]=array_replace($body[$kind],['type'=>$kind,'role'=>'reference_'.$kind]);
            try{Video::submit($consumptionId,$request);}catch(\Throwable $e){\think\facade\Log::warning('Motion sync submit: '.get_class($e));}
            $latest=AiConsumptionLog::find($consumptionId);self::syncConsumption($latest->toArray(),(int)$job['id']);
            if(in_array($latest['run_status'],['running','success'],true))Queue::enqueueQueryResult($consumptionId);
            return true;
        }finally{SubmitLockService::release($lock);}
    }
    public static function syncConsumption(array $c,int $id): void
    {
        if(($c['app_code']??'')!==MotionSyncService::APP)return;
        $job=self::table()->where(['id'=>$id,'tenant_id'=>$c['tenant_id'],'user_id'=>$c['user_id'],'consumption_id'=>$c['id']])->find();if(!$job)return;
        $status=MarketVideoSubmissionService::pending($c)||($c['run_status']==='reserved'&&$job['submit_started_at']>0)?'unknown':($job['submit_started_at']>0?'running':'queued');
        if(in_array($c['run_status'],['failed','canceled'],true)&&$c['billing_status']==='refunded')$status=$c['run_status'];
        $assets=[];
        if($c['run_status']==='success'&&$c['billing_status']==='settled'){
            AiTaskResultAssetService::recordConsumptionAssets((int)$c['id'],true);
            $assets=AiTaskResultAsset::where(['consumption_id'=>$c['id'],'tenant_id'=>$c['tenant_id'],'user_id'=>$c['user_id'],'asset_type'=>'video','transfer_status'=>'stored'])->select()->toArray();
            $expected=count((array)(MotionSyncService::json($c['response_summary'])['videos']??[]));
            if($assets&&count($assets)===$expected)$status='success';
        }
        Db::transaction(static function()use($c,$id,$status,$assets){
            $row=self::table()->where('id',$id)->lock(true)->find();if(!$row||in_array($row['status'],['success','failed','canceled'],true))return;
            foreach($assets as $asset){$meta=(array)$asset['storage_meta'];MotionSyncService::table('result')->extra('IGNORE')->insert([
                'tenant_id'=>$c['tenant_id'],'user_id'=>$c['user_id'],'task_id'=>$id,'asset_id'=>$asset['id'],'video_uri'=>$asset['local_uri'],
                'cover_uri'=>$meta['cover_uri']??'','duration'=>(float)($meta['duration']??0),'width'=>(int)($meta['width']??0),'height'=>(int)($meta['height']??0),
                'storage_scope'=>$asset['storage_scope'],'storage_engine'=>$asset['storage_engine'],'storage_domain'=>$asset['storage_domain'],'create_time'=>time()]);}
            self::table()->where('id',$id)->update(['status'=>$status,'billing_status'=>$c['billing_status'],
                'user_charge_points'=>$c['billing_status']==='settled'?$c['actual_user_price']:($c['billing_status']==='refunded'?0:$c['reserved_user_price']),
                'update_time'=>time()]);
        });
    }
    public static function dto(array $row,bool $admin=false): array
    {
        $body=MotionSyncService::json($row['request_json'])['body']??[];
        $results=MotionSyncService::table('result')->where(['task_id'=>$row['id'],'tenant_id'=>$row['tenant_id'],'user_id'=>$row['user_id'],'delete_time'=>0])->select()->toArray();
        $dto=['id'=>(int)$row['id'],'project_id'=>(int)$row['project_id'],'status'=>$row['status'],'billing_status'=>$row['billing_status'],
            'user_charge_points'=>(float)$row['user_charge_points'],'configuration'=>MotionSyncService::publicBody($body,(int)$row['tenant_id']),
            'videos'=>MotionSyncService::publicBody($results,(int)$row['tenant_id']),'create_time'=>(int)$row['create_time'],
            'error'=>$row['status']==='unknown'?'模型受理或费用待核对，请保留任务，勿重复生成':($row['status']==='failed'?'创作失败，冻结点数已退回':'')];
        if($admin){$c=AiConsumptionLog::find((int)$row['consumption_id']);$dto+=['tenant_id'=>(int)$row['tenant_id'],'user_id'=>(int)$row['user_id'],'app_task_id'=>(int)$row['app_task_id'],'consumption_id'=>(int)$row['consumption_id'],
            'upstream_task_id'=>(string)($c['upstream_task_id']??''),'upstream_request_id'=>(string)($c['upstream_request_id']??''),'pricing_snapshot'=>$c['price_snapshot']??[],
            'submitted_parameters'=>MotionSyncService::publicBody((array)(MotionSyncService::json($row['request_json'])['request']??[]),(int)$row['tenant_id'])];}
        return $dto;
    }
    public static function lists(int $tenant,int $user,array $p,bool $admin=false): array
    {
        $q=self::table()->where('delete_time',0);if(!$admin||$tenant>0)$q->where('tenant_id',$tenant);if(!$admin)$q->where('user_id',$user);
        if($admin){foreach(['tenant_id','user_id'] as $key)if(!empty($p[$key])&&($key!=='tenant_id'||$tenant===0))$q->where($key,(int)$p[$key]);
            foreach(['start_time'=>'>=','end_time'=>'<='] as $key=>$op)if(!empty($p[$key]))$q->where('create_time',$op,(int)$p[$key]);
            $search=MotionSyncService::text($p['keyword']??'',160);if($search!==''){$ids=Db::name('ai_consumption_log')->where('app_code',MotionSyncService::APP)->where(static fn($a)=>$a->where('upstream_task_id',$search)->whereOr('upstream_request_id',$search))->column('id');
                $q->where(static function($a)use($search,$ids){$a->whereLike('prompt','%'.addcslashes($search,'\\%_').'%');if(ctype_digit($search))$a->whereOr('id',(int)$search)->whereOr('app_task_id',(int)$search);if($ids)$a->whereOr('consumption_id','in',$ids);});}
        }
        if(($p['status']??'')!==''){if(!in_array($p['status'],['queued','running','unknown','success','failed','canceled'],true))throw new InvalidArgumentException('任务状态无效');$q->where('status',$p['status']);}
        $page=max(1,(int)($p['page_no']??1));$size=max(1,min(40,(int)($p['page_size']??20)));$count=(clone $q)->count();
        return ['lists'=>array_map(static fn($r)=>self::dto($r,$admin),$q->order('id','desc')->page($page,$size)->select()->toArray()),'count'=>$count];
    }
    public static function cancel(int $tenant,int $user,int $id): array
    {
        return Db::transaction(static function()use($tenant,$user,$id){$row=self::owned($tenant,$user,$id,false,true);
            if($row['status']!=='queued'||$row['submit_started_at']>0)throw new InvalidArgumentException('任务已开始提交，暂不支持取消');
            Video::cancel((int)$row['consumption_id']);self::table()->where('id',$id)->update(['status'=>'canceled','billing_status'=>'refunded','user_charge_points'=>0,'update_time'=>time()]);return self::dto(self::owned($tenant,$user,$id));});
    }
    public static function recover(int $tenant,int $user,int $id): array
    {
        $row=self::owned($tenant,$user,$id);if(!in_array($row['status'],['running','unknown'],true))return self::dto($row);
        $c=AiConsumptionLog::find((int)$row['consumption_id']);if($c&&!MarketVideoSubmissionService::pending($c->toArray()))Queue::enqueueQueryResult((int)$c['id'],0,true);
        return self::dto($row);
    }
    public static function delete(int $tenant,int $user,int $id,bool $admin=false): void
    {
        Db::transaction(static function()use($tenant,$user,$id,$admin){$row=self::owned($tenant,$user,$id,$admin,true);
            if(!in_array($row['status'],['success','failed','canceled'],true))throw new InvalidArgumentException('排队中、运行中或待核对的任务不能删除');
            self::table()->where('id',$id)->update(['delete_time'=>time(),'update_time'=>time()]);
            MotionSyncService::table('result')->where('task_id',$id)->update(['delete_time'=>time()]);
        });
    }
}
