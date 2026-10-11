<?php
namespace app\common\service\open_platform;
use app\common\service\power\TenantPowerMarketService;
use app\common\service\point\PointService;
use app\common\model\ai\AiConsumptionLog;
use app\common\model\ai\AiAppTask;
use think\facade\Db;
final class OpenMarketBilling
{
    public static function quote(int $tenantId,array $operation,array $params): array
    {
        if(!empty($operation['history']))return ['user'=>0,'tenant'=>0,'snapshot'=>['contract'=>$operation]];
        $global=OpenStore::settings(0);$config=OpenStore::requireEnabled($tenantId);$productId=(int)($operation['product_id']??0);
        if(!in_array((string)$productId,$config['models'],true)||!in_array((string)$productId,$global['models'],true))throw new OpenException('permission_denied','该商城能力未开放',403);
        if(empty($operation['billable']))return ['user'=>0,'tenant'=>0,'snapshot'=>['contract'=>$operation,'product_id'=>$productId]];
        $product=TenantPowerMarketService::detail($tenantId,$productId);if(!$product||empty($product['status']))throw new OpenException('permission_denied','商城产品已下架',403);
        $user=0.;$tenant=0.;$rules=[];$skus=array_column($product['skus'],null,'id');
        foreach($operation['billing'] as $rule){$sku=$skus[(int)$rule['sku_id']]??null;if(!$sku||empty($sku['sale_status']))throw new OpenException('pricing_unavailable','计费 SKU 已下架',503);
            foreach(OpenStore::json($sku['locked_params']??[]) as $key=>$value){$parameterPath=$rule['parameter_paths'][$key]??$key;$actual=OpenSchema::get($params,$parameterPath);if($actual===null)$actual=OpenSchema::get($operation['input']??[],'properties.'.$parameterPath.'.default');if($actual===null||$actual!=$value)throw new OpenException('invalid_sku','参数与已发布 SKU 不匹配');}
            $units=$rule['basis']==='usage'?(float)$rule['max_units']:(empty($rule['input_path'])?1:OpenSchema::get($params,$rule['input_path']));if(is_array($units))$units=count($units);if(!is_numeric($units)||(float)$units<0||(float)$units>(float)$rule['max_units'])throw new OpenException('invalid_usage','请求超出已发布计费边界');
            $size=max(1,(float)($sku['usage_unit_size']??1));$prices=['user'=>(float)$sku['sale_points'],'tenant'=>(float)$sku['platform_sale_points']];
            $entry=$rule+['units'=>(float)$units,'unit_size'=>$size,'prices'=>$prices,'sku_key'=>$sku['sku_key'],'usage_unit'=>$sku['usage_unit']];$rules[]=$entry;$user+=$prices['user']*(float)$units/$size;$tenant+=$prices['tenant']*(float)$units/$size;
        }
        return ['user'=>round($user,2),'tenant'=>round($tenant,2),'snapshot'=>['billing_owner'=>'gateway','contract'=>$operation,'rules'=>$rules,'product_id'=>$productId]];
    }
    public static function reserve(array $request,array $budget): void
    {
        if(empty($budget['snapshot']['rules']))return;
        Db::transaction(function()use($request,$budget){$now=time();$task=AiAppTask::create(['task_no'=>'OP'.$request['request_id'],'tenant_id'=>$request['tenant_id'],'user_id'=>$request['user_id'],'app_code'=>'open_platform','action_code'=>'market','business_table'=>'open_platform_request','business_id'=>$request['id'],'parent_task_id'=>0,'status'=>'submitting','progress'=>0,'request_summary'=>['request_id'=>$request['request_id']],'result_summary'=>[],'estimated_tenant_cost'=>$budget['tenant'],'estimated_user_price'=>$budget['user'],'actual_tenant_cost'=>0,'actual_user_price'=>0,'idempotency_key'=>$request['request_id'],'create_time'=>$now,'update_time'=>$now,'finish_time'=>0]);
            $c=AiConsumptionLog::create(['consume_no'=>$request['request_id'],'app_task_id'=>$task['id'],'tenant_id'=>$request['tenant_id'],'user_id'=>$request['user_id'],'app_code'=>'open_platform','action_code'=>'market','resource_type'=>'market','product_id'=>$budget['snapshot']['product_id'],'sku_id'=>$budget['snapshot']['rules'][0]['sku_id'],'protocol'=>$budget['snapshot']['contract']['executor'],'provider'=>'power_market','quantity'=>0,'usage_unit'=>'mixed','usage_snapshot'=>[],'price_snapshot'=>$budget['snapshot'],'request_summary'=>['request_id'=>$request['request_id']],'response_summary'=>[],'run_status'=>'submitting','billing_status'=>'reserved','reserved_tenant_cost'=>$budget['tenant'],'reserved_user_price'=>$budget['user'],'actual_tenant_cost'=>0,'actual_user_price'=>0,'tenant_point_sn'=>$request['request_id'].'-reserve','user_point_sn'=>$request['request_id'].'-reserve','create_time'=>$now,'update_time'=>$now,'finish_time'=>0]);
            PointService::reserveBusinessAmountsInCurrentTransaction((int)$request['tenant_id'],(int)$request['user_id'],$budget['tenant'],$budget['user'],$request['request_id'].'-reserve','开放平台预占',['consumption_id'=>$c['id'],'app_code'=>'open_platform']);
        });
    }
    public static function settle(array $request,array $result,bool $failed=false): void
    {
        Db::transaction(function()use($request,$result,$failed){$c=AiConsumptionLog::where(['tenant_id'=>$request['tenant_id'],'user_id'=>$request['user_id'],'consume_no'=>$request['request_id']])->lock(true)->findOrEmpty();if($c->isEmpty()||in_array($c['billing_status'],['settled','refunded'],true))return;
            $snapshot=OpenStore::json($c['price_snapshot']);$user=0.;$tenant=0.;$usage=[];
            foreach($snapshot['rules']??[] as $rule){$units=$failed?0:($rule['basis']==='input'?$rule['units']:OpenSchema::get($result,$rule['usage_path']));if(is_array($units))$units=count($units);if(!is_numeric($units)||(float)$units<0){$c->save(['billing_status'=>'pending_usage','run_status'=>'success']);OpenAdmission::finish($request['request_id'],['billing_status'=>'pending_usage','status'=>'success','next_run_time'=>time()+60]);return;}if((float)$units>(float)$rule['max_units'])throw new OpenException('usage_boundary_exceeded','可信用量超过契约边界，等待人工核对',502);$usage[]=['sku_id'=>$rule['sku_id'],'units'=>$units];$user+=(float)$units/$rule['unit_size']*$rule['prices']['user'];$tenant+=(float)$units/$rule['unit_size']*$rule['prices']['tenant'];}
            $user=round($user,2);$tenant=round($tenant,2);PointService::settleReservedBusinessAmountsInCurrentTransaction((int)$request['tenant_id'],(int)$request['user_id'],(float)$c['reserved_tenant_cost'],(float)$c['reserved_user_price'],$tenant,$user,$request['request_id'],'开放平台结算',['consumption_id'=>$c['id'],'app_code'=>'open_platform']);
            $state=$failed?'refunded':'settled';$c->save(['run_status'=>$failed?'failed':'success','billing_status'=>$state,'usage_snapshot'=>$usage,'actual_tenant_cost'=>$tenant,'actual_user_price'=>$user,'finish_time'=>time()]);AiAppTask::where('id',$c['app_task_id'])->update(['status'=>$failed?'failed':'success','progress'=>100,'actual_tenant_cost'=>$tenant,'actual_user_price'=>$user,'finish_time'=>time()]);OpenAdmission::finish($request['request_id'],['status'=>$failed?'failed':'success','billing_status'=>$state,'actual_user'=>$user,'actual_tenant'=>$tenant,'active'=>0,'budget_user'=>0,'budget_tenant'=>0,'finish_time'=>time(),'next_run_time'=>0]);
        });
    }
}
