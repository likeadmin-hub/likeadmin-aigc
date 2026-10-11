<?php
namespace app\common\service\open_platform;
use app\common\model\ai\AiConsumptionLog;
use app\common\model\ai\AiAppTask;
use think\facade\Db;
/** Native adapters preserve reservations when a submission has no definitive receipt. */
final class OpenSubmission
{
    public static function start(int $consumptionId): void
    {
        if(OpenCallContext::id()==='')return;
        Db::transaction(function()use($consumptionId){
            $row=OpenStore::table('request')->where('request_id',OpenCallContext::id())->lock(true)->find();
            $c=AiConsumptionLog::lock(true)->findOrEmpty($consumptionId);
            if(!$row||$c->isEmpty()||(int)$c['tenant_id']!==(int)$row['tenant_id']||(int)$c['user_id']!==(int)$row['user_id']||!in_array($consumptionId,array_map('intval',OpenStore::json($row['consumption_ids'])),true))throw new \RuntimeException('Submission ownership mismatch');
            $snapshot=OpenStore::json($row['snapshot']);unset($snapshot['provider_rejection_confirmed'],$snapshot['provider_error_blob']);
            OpenAdmission::finish($row['request_id'],['snapshot'=>OpenStore::encode($snapshot)]);
            if((string)$c['run_status']==='reserved')$c->save(['run_status'=>'submitting','update_time'=>time()]);
        });
    }
    public static function holdUnknown(int $consumptionId): bool
    {
        if(OpenCallContext::id()===''||OpenTextLifecycle::rejectionConfirmed())return false;
        Db::transaction(function()use($consumptionId){
            $row=OpenStore::table('request')->where('request_id',OpenCallContext::id())->lock(true)->find();
            $c=AiConsumptionLog::lock(true)->findOrEmpty($consumptionId);
            if(!$row||$c->isEmpty()||(int)$c['tenant_id']!==(int)$row['tenant_id']||(int)$c['user_id']!==(int)$row['user_id']||!in_array($consumptionId,array_map('intval',OpenStore::json($row['consumption_ids'])),true))throw new \RuntimeException('Submission ownership mismatch');
            if(in_array((string)$c['billing_status'],['settled','refunded'],true))return;
            if((string)$c['run_status']==='success'||(string)$c['upstream_task_id']!==''){
                $known=(string)$c['run_status']==='success';
                if($known)$c->save(['billing_status'=>'settlement_failed','error_code'=>'settlement_failed','error_message'=>'结果已确认，结算待核对','update_time'=>time()]);
                OpenAdmission::finish($row['request_id'],['status'=>$known?'success':'running','billing_status'=>$known?'settlement_failed':'pending','active'=>1,'next_run_time'=>time()+60]);return;
            }
            $c->save(['run_status'=>'pending_confirmation','error_code'=>'pending_confirmation','error_message'=>'上游受理状态待确认','finish_time'=>0,'update_time'=>time()]);
            AiAppTask::where('id',$c['app_task_id'])->update(['status'=>'running','finish_time'=>0,'update_time'=>time()]);
            OpenAdmission::finish($row['request_id'],['status'=>'pending_confirmation','billing_status'=>'pending_confirmation','active'=>1,'next_run_time'=>time()+300]);
        });return true;
    }
    public static function pending(array $consumption): bool
    {
        return (string)($consumption['upstream_task_id']??'')===''&&in_array((string)($consumption['run_status']??''),['submitting','pending_confirmation'],true)&&!empty(OpenStore::json($consumption['price_snapshot']??[])['_open_platform_request_id']);
    }
}
