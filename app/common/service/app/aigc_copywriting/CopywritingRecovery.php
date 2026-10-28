<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_copywriting;
final class CopywritingRecovery
{
    public static function reconcile(int $tenantId,int $id): array
    {
        $task=CopywritingStore::owned('task',$tenantId,$id);$cid=(int)$task['consumption_id'];
        if($cid){$c=\think\facade\Db::name('ai_consumption_log')->where(['tenant_id'=>$tenantId,'id'=>$cid,'app_code'=>'aigc_copywriting'])->find();if($c){$state=['deducted'=>'settled','refunded'=>'refunded','pending_usage'=>'pending_usage'][$c['billing_status']]??'pending_usage';CopywritingStore::table('task')->where(['id'=>$id,'tenant_id'=>$tenantId])->update(['billing_state'=>$state]);}}
        return CopywritingTaskService::publicTask(CopywritingStore::owned('task',$tenantId,$id));
    }
    public static function review(int $adminId,int $tenantId,int $id,string $note): array
    {
        CopywritingStore::owned('task',$tenantId,$id);CopywritingStore::table('task')->where(['id'=>$id,'tenant_id'=>$tenantId])->update(['review_state'=>'reviewed','review_note'=>mb_substr($note,0,1000),'reviewer_id'=>$adminId,'reviewed_at'=>time()]);CopywritingStore::audit($tenantId,$adminId,'review_uncertain','task',$id);return self::reconcile($tenantId,$id);
    }
}
