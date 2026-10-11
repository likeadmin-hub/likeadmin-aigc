<?php
namespace app\common\service\open_platform;
use app\common\model\ai\AiAppTask;
use app\common\model\ai\AiConsumptionLog;
use think\facade\Db;
/** Preserves provider evidence independently of wallet settlement. */
final class OpenTextLifecycle
{
    /** Commit provider output before attempting wallet settlement or result validation. */
    public static function recordResult(array $context,array $result,array $usage): void
    {
        $id=OpenCallContext::id();if($id==='')return;
        Db::transaction(function()use($context,$result,$usage,$id){
            $row=OpenStore::table('request')->where('request_id',$id)->lock(true)->find();
            $c=AiConsumptionLog::lock(true)->findOrEmpty((int)$context['consumption']['id']);
            if(!$row||$c->isEmpty()||(int)$c['tenant_id']!==(int)$row['tenant_id']||(int)$c['user_id']!==(int)$row['user_id']||!in_array((int)$c['id'],array_map('intval',OpenStore::json($row['consumption_ids'])),true))throw new \RuntimeException('Text result ownership mismatch');
            $snapshot=OpenStore::json($row['snapshot']);$snapshot['native_text_result_blob']=OpenVault::seal(OpenStore::encode($result));
            OpenAdmission::finish($id,['snapshot'=>OpenStore::encode($snapshot),'status'=>'success','next_run_time'=>time()+60]);
            if(!in_array((string)$c['billing_status'],['settled','refunded'],true))$c->save(['run_status'=>'success','usage_snapshot'=>$usage+['settlement_basis'=>'awaiting_settlement'],'upstream_request_id'=>(string)($result['provider_request_id']??''),'response_summary'=>['output_length'=>mb_strlen((string)($result['content']??''),'UTF-8')],'update_time'=>time()]);
        });
    }
    public static function rejectionConfirmed(): bool
    {
        if(OpenCallContext::id()==='')return false;
        return !empty(OpenStore::json(OpenStore::table('request')->where('request_id',OpenCallContext::id())->value('snapshot'))['provider_rejection_confirmed']);
    }
    public static function defer(array $context,?array $result,array $usage,\Throwable $error): void
    {
        $id=OpenCallContext::id();if($id==='')throw new \LogicException('Open text context required');
        Db::transaction(function()use($context,$result,$usage,$id){
            $row=OpenStore::table('request')->where('request_id',$id)->lock(true)->find();if(!$row)throw new \RuntimeException('Open request not found');
            $c=AiConsumptionLog::lock(true)->findOrEmpty((int)$context['consumption']['id']);if($c->isEmpty())throw new \RuntimeException('Text consumption not found');
            if(in_array((string)$c['billing_status'],['settled','refunded'],true))return;
            $known=$result!==null;$billing=$known?'settlement_failed':'pending_confirmation';$now=time();
            $c->save(['run_status'=>$known?'success':'pending_confirmation','billing_status'=>$billing,'usage_snapshot'=>$usage+['settlement_basis'=>$known?'actual_usage':'awaiting_acceptance'],'upstream_request_id'=>(string)($result['provider_request_id']??''),'response_summary'=>$known?['output_length'=>mb_strlen((string)($result['content']??''),'UTF-8')]:[],'error_code'=>$billing,'error_message'=>$known?'结果已确认，结算待核对':'提交受理状态待确认','finish_time'=>$known?$now:0,'update_time'=>$now]);
            AiAppTask::where('id',$c['app_task_id'])->update(['status'=>$known?'success':'running','progress'=>$known?100:10,'finish_time'=>$known?$now:0,'update_time'=>$now]);
            $snapshot=OpenStore::json($row['snapshot']);
            if($known)$snapshot['native_text_result_blob']=OpenVault::seal(OpenStore::encode($result));
            OpenAdmission::finish($id,['snapshot'=>OpenStore::encode($snapshot),'status'=>$known?'success':'pending_confirmation','billing_status'=>$billing,'active'=>1,'error_code'=>$billing,'error_message'=>$known?'结果已确认，结算待核对':'提交受理状态待确认','next_run_time'=>$now+60]);
        });
    }
    /** Recovery only reads committed evidence; it neither resubmits nor back-charges. */
    public static function refresh(array $row): void
    {
        $ids=OpenStore::json($row['consumption_ids']);if(!$ids)return;
        $logs=AiConsumptionLog::where(['tenant_id'=>$row['tenant_id'],'user_id'=>$row['user_id']])->whereIn('id',$ids)->select()->toArray();
        if(count($logs)!==count($ids))return;
        $known=count(array_filter($logs,fn($c)=>$c['run_status']==='success'))===count($logs);
        $rejected=count(array_filter($logs,fn($c)=>$c['run_status']==='failed'&&$c['billing_status']==='refunded'))===count($logs);
        $billing=OpenToolTasks::billing($row);$terminal=$known&&in_array($billing,['settled','refunded','none'],true)||$rejected;
        OpenAdmission::finish($row['request_id'],['status'=>$known?'success':($rejected?'failed':'pending_confirmation'),'billing_status'=>$rejected?'refunded':$billing,'active'=>$terminal?0:1,'finish_time'=>$known||$rejected?($row['finish_time']?:time()):0,'next_run_time'=>$terminal?0:time()+300]);
    }
}
