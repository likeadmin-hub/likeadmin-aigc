<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_copywriting;

use app\common\service\power\MarketTextModelRuntimeService;
use think\facade\Db;

final class CopywritingRecovery
{
    /** Query original evidence only. Never resubmit an uncertain supplier call. */
    public static function reconcile(int $tenantId,int $id): array
    {
        $task=CopywritingStore::owned('task',$tenantId,$id);
        $cid=(int)$task['consumption_id'];
        if(!$cid)return CopywritingTaskService::publicTask($task);
        $c=Db::name('ai_consumption_log')->where(['tenant_id'=>$tenantId,'user_id'=>$task['user_id'],'id'=>$cid,'app_code'=>'aigc_copywriting'])->find();
        if(!$c)return CopywritingTaskService::publicTask($task);
        $receipt=CopywritingReceipt::read($task);
        if($receipt){
            try {MarketTextModelRuntimeService::settleCopywritingReceipt($tenantId,(int)$task['user_id'],$cid,$receipt);}
            catch(\Throwable $e){\think\facade\Log::error('Copywriting receipt settlement pending: '.$cid.' '.get_class($e));}
            $c=Db::name('ai_consumption_log')->where('id',$cid)->find();
        }
        $state=['settled'=>'settled','refunded'=>'refunded','deducted'=>'settled'][$c['billing_status']]??'pending_usage';
        $changes=['billing_state'=>$state];
        if($receipt&&($receipt['result']['content_source']??'content')!=='content'){$changes+=['result_text'=>'','status'=>'failed','submission_state'=>'finished','error'=>'模型未返回最终正文，请调整思考模式后重新生成','lease_until'=>0,'finished_at'=>time()];}
        elseif($receipt&&!empty($receipt['complete'])){
            $changes+=['result_text'=>(string)($receipt['result']['content']??''),'provider_request_id'=>(string)($receipt['result']['provider_request_id']??$task['provider_request_id']),'submission_state'=>'finished','lease_until'=>0,'finished_at'=>time(),'status'=>!empty($task['cancel_requested'])?'canceled':'success','error'=>''];
        }elseif($state==='refunded'&&$c['run_status']==='failed'&&in_array($task['status'],['pending','running'],true)){
            $changes+=['status'=>'failed','submission_state'=>'finished','error'=>(string)$c['error_message'],'lease_until'=>0,'finished_at'=>time()];
        }
        // Recovery may update a terminal task's billing state after a late settlement.
        $different=false;foreach($changes as $key=>$value)if((string)($task[$key]??'')!==(string)$value){$different=true;break;}
        if($different)CopywritingEventService::save($id,$changes,'snapshot',true);
        return CopywritingTaskService::publicTask(CopywritingStore::owned('task',$tenantId,$id));
    }

    public static function sweep(int $limit=20): int
    {
        $rows=CopywritingStore::table('task')->where(function($q){$q->where('submission_state','uncertain')->whereOr('billing_state','pending_usage');})->where('update_time','<',time()-30)->order('update_time')->limit($limit)->select()->toArray();
        foreach($rows as $row){try{self::reconcile((int)$row['tenant_id'],(int)$row['id']);}catch(\Throwable $e){\think\facade\Log::error('Copywriting recovery pending: '.$row['id'].' '.get_class($e));}finally{CopywritingStore::table('task')->where(['tenant_id'=>$row['tenant_id'],'id'=>$row['id']])->update(['update_time'=>time()]);}}
        return count($rows);
    }

    public static function review(int $adminId,int $tenantId,int $id,string $note): array
    {
        if(trim($note)==='')throw new \InvalidArgumentException('请填写核对说明');
        CopywritingStore::owned('task',$tenantId,$id);
        CopywritingStore::table('task')->where(['id'=>$id,'tenant_id'=>$tenantId])->update(['review_state'=>'reviewed','review_note'=>mb_substr($note,0,1000),'reviewer_id'=>$adminId,'reviewed_at'=>time()]);
        CopywritingStore::audit($tenantId,$adminId,'review_uncertain','task',$id);
        return CopywritingTaskService::publicTask(CopywritingStore::owned('task',$tenantId,$id));
    }
}
