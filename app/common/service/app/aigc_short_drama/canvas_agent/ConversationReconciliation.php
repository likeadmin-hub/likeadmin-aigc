<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_short_drama\canvas_agent;

use think\facade\Db;

/**
 * Reconcile only authoritative, already-terminal local billing records.
 *
 * This never asks a Provider to retry, cancel, or refund.  It exists to
 * release a conversation that crossed the durable submission boundary and
 * subsequently received a known final app-task/ledger outcome locally.
 */
final class ConversationReconciliation
{
    public static function reconcile(int $tenant,int $user,int $runId): string
    {
        return Db::transaction(function () use ($tenant,$user,$runId): string {
            $run=Db::name(ConversationStore::PREFIX.'run')->where([
                'id'=>$runId,'tenant_id'=>$tenant,'user_id'=>$user,'delete_time'=>0,
            ])->lock(true)->find();
            if (!$run) return 'not_found';
            if ($run['status']!=='needs_reconciliation') return 'unchanged';
            $outbox=Db::name(ConversationStore::PREFIX.'outbox')->where([
                'tenant_id'=>$tenant,'user_id'=>$user,'canvas_id'=>$run['canvas_id'],'run_id'=>$runId,
            ])->lock(true)->find();
            if (!$outbox || $outbox['state']!=='needs_reconciliation') return 'unchanged';

            $task=Db::name('ai_app_task')->where([
                'tenant_id'=>$tenant,'user_id'=>$user,'app_code'=>'aigc_short_drama',
                'business_table'=>ConversationStore::PREFIX.'run','business_id'=>$runId,
            ])->order('id','desc')->lock(true)->find();
            if (!$task || !in_array((string)$task['status'],['success','failed','canceled'],true)) return 'pending_external_outcome';
            $usage=Db::name('ai_consumption_log')->where([
                'app_task_id'=>(int)$task['id'],'tenant_id'=>$tenant,'user_id'=>$user,
            ])->order('id','desc')->lock(true)->find();
            if (!$usage || !in_array((string)$usage['billing_status'],['settled','refunded'],true)) return 'pending_usage';

            $stopped=(bool)Db::name(ConversationStore::PREFIX.'event')->where([
                'run_id'=>$runId,'kind'=>'run.stop_requested',
            ])->lock(true)->find();
            $code=(string)$task['status']==='failed' || (string)$usage['billing_status']==='refunded'
                ? 'UPSTREAM_FAILED_REFUNDED'
                : ($stopped ? 'UPSTREAM_COMPLETED_AFTER_STOP' : 'UPSTREAM_COMPLETED_UNPUBLISHED');
            Db::name(ConversationStore::PREFIX.'run')->where('id',$runId)->update([
                'status'=>'failed','error_code'=>$code,'version'=>(int)$run['version']+1,'update_time'=>time(),
            ]);
            Db::name(ConversationStore::PREFIX.'outbox')->where('id',$outbox['id'])->update([
                'state'=>'failed','lease_until'=>0,'update_time'=>time(),
            ]);
            Db::name(ConversationStore::PREFIX.'thread')->where([
                'id'=>$run['thread_id'],'tenant_id'=>$tenant,'user_id'=>$user,'canvas_id'=>$run['canvas_id'],
                'active_run_id'=>$runId,'delete_time'=>0,
            ])->update(['active_run_id'=>0,'update_time'=>time()]);
            self::event($run,'run.reconciled',['status'=>'failed','code'=>$code,'app_task_id'=>(int)$task['id'],'billing_status'=>(string)$usage['billing_status']]);
            return 'reconciled';
        });
    }

    private static function event(array $run,string $kind,array $payload): void
    {
        $last=Db::name(ConversationStore::PREFIX.'event')->where('run_id',$run['id'])->order('sequence','desc')->lock(true)->find();
        Db::name(ConversationStore::PREFIX.'event')->insert([
            'tenant_id'=>$run['tenant_id'],'user_id'=>$run['user_id'],'canvas_id'=>$run['canvas_id'],
            'thread_id'=>$run['thread_id'],'run_id'=>$run['id'],'sequence'=>(int)($last['sequence']??0)+1,
            'kind'=>$kind,'payload_json'=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),'create_time'=>time(),
        ]);
    }
}
