<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use think\facade\Db;
use app\common\service\app\aigc_short_drama\ShortDramaCanvasService as Canvas;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationStore as Store;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationExecution as Execution;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationReconciliation as Reconciliation;

function reconciliationRun(int $canvas,string $key): array {
    $thread=Store::create(91001,92001,$canvas,$key)['id'];
    $run=Store::enqueue(91001,92001,$canvas,$thread,['request_key'=>$key,'content'=>'reconcile fixture','base_revision'=>0],['settings'=>[],'skill'=>[]])['run_id'];
    $claim=Execution::claim(91001,92001,$run);
    Execution::authorizeSubmission(91001,92001,$run,$claim['token'],$claim['fence']);
    Execution::unknown(91001,92001,$run,$claim['token'],$claim['fence']);
    return [$thread,$run];
}
function reconciliationLedger(int $run,string $status,string $billing): int {
    $now=time();
    $task=(int)Db::name('ai_app_task')->insertGetId([
        'task_no'=>'rec-'.$run,'tenant_id'=>91001,'user_id'=>92001,'app_code'=>'aigc_short_drama',
        'action_code'=>'short_drama_canvas_agent_chat','business_table'=>Store::PREFIX.'run','business_id'=>$run,
        'status'=>$status,'progress'=>100,'idempotency_key'=>'rec-'.$run,'create_time'=>$now,'update_time'=>$now,'finish_time'=>$now,
    ]);
    Db::name('ai_consumption_log')->insert([
        'consume_no'=>'rec-'.$run,'app_task_id'=>$task,'tenant_id'=>91001,'user_id'=>92001,
        'app_code'=>'aigc_short_drama','action_code'=>'short_drama_canvas_agent_chat','run_status'=>$status,
        'billing_status'=>$billing,'create_time'=>$now,'update_time'=>$now,'finish_time'=>$now,
    ]);
    return $task;
}
Db::startTrans();
try {
    Db::name('aigc_short_drama_config')->insert(['tenant_id'=>91001,'config_json'=>'{"canvas_agent":{"enabled":true}}','status'=>1]);
    $canvas=(int)Canvas::create(91001,92001,['title'=>'P2 reconciliation'])['id'];

    [, $pending]=reconciliationRun($canvas,'pending');
    agentCheck(Reconciliation::reconcile(91001,92001,$pending)==='pending_external_outcome','unknown without local terminal task remains unresolved');

    [, $refunded]=reconciliationRun($canvas,'refunded');
    $refundedTask=reconciliationLedger($refunded,'failed','refunded');
    agentCheck(Reconciliation::reconcile(91001,92001,$refunded)==='reconciled','failed refunded task reconciles without Provider call');
    $row=Db::name(Store::PREFIX.'run')->where('id',$refunded)->find();
    agentCheck($row['status']==='failed' && $row['error_code']==='UPSTREAM_FAILED_REFUNDED','refunded outcome has explicit terminal code');
    agentCheck((int)Db::name(Store::PREFIX.'thread')->where('id',$row['thread_id'])->value('active_run_id')===0,'refunded reconciliation releases thread');
    agentCheck(Db::name(Store::PREFIX.'outbox')->where('run_id',$refunded)->value('state')==='failed','refunded reconciliation closes outbox');
    agentCheck(Db::name('ai_app_task')->where('id',$refundedTask)->value('status')==='failed','reconciliation never mutates authoritative task');

    [$stopThread,$settled]=reconciliationRun($canvas,'settled-stop');
    Execution::stop(91001,92001,$canvas,$stopThread,$settled);
    $settledTask=reconciliationLedger($settled,'success','settled');
    agentCheck(Reconciliation::reconcile(91001,92001,$settled)==='reconciled','settled request after stop is closed without fake cancellation');
    $row=Db::name(Store::PREFIX.'run')->where('id',$settled)->find();
    agentCheck($row['status']==='failed' && $row['error_code']==='UPSTREAM_COMPLETED_AFTER_STOP','post-submit stop preserves settled-upstream fact');
    agentCheck(Db::name(Store::PREFIX.'event')->where(['run_id'=>$settled,'kind'=>'run.reconciled'])->count()===1,'one durable reconciliation event');
    agentCheck(Reconciliation::reconcile(91001,92001,$settled)==='unchanged','reconciliation is idempotent');
    agentCheck(Db::name('ai_app_task')->where('id',$settledTask)->value('status')==='success','settled task is not refunded or rewritten');
} finally {Db::rollback();}
echo "NOT_RUN provider cancel endpoint, provider-side usage lookup and production scheduler; local terminal ledger reconciliation only\n";
