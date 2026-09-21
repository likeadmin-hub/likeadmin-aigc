<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use think\facade\Db;
use app\common\service\app\aigc_short_drama\ShortDramaCanvasService as Canvas;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationStore as Store;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationExecution as Execution;
use app\common\service\app\aigc_short_drama\canvas_agent\GraphService as Graph;
function rejectsExecution(callable $action,string $code): void {
    try {$action();} catch (RuntimeException $error) {agentCheck($error->getMessage()===$code,$code);return;}
    throw new RuntimeException('Expected '.$code);
}
Db::startTrans();
try {
    Db::name('aigc_short_drama_config')->insert(['tenant_id'=>91001,'config_json'=>'{"canvas_agent":{"enabled":true}}','status'=>1,'create_time'=>time(),'update_time'=>time()]);
    $canvas=Canvas::create(91001,92001,['title'=>'P2 execution fixture'])['id'];
    $before=Db::name(Graph::TABLE)->where('id',$canvas)->find();
    foreach (['success','expire','unknown','late'] as $scenario) {
        $thread=Store::create(91001,92001,$canvas,$scenario)['id'];
        $ack=Store::enqueue(91001,92001,$canvas,$thread,['request_key'=>$scenario,'content'=>'仅问答','base_revision'=>0],['settings'=>['reasoning_model'=>'isolated-model'],'skill'=>[]]);
        $run=$ack['run_id'];
        rejectsExecution(fn()=>Execution::claim(91002,92001,$run),'RUN_NOT_FOUND');
        rejectsExecution(fn()=>Execution::claim(91001,92002,$run),'RUN_NOT_FOUND');
        $claim=Execution::claim(91001,92001,$run);
        agentCheck($claim!==null && $claim['settings']['reasoning_model']==='isolated-model',$scenario.' claims frozen reasoning model');
        agentCheck(Execution::claim(91001,92001,$run)===null,$scenario.' cannot claim twice');
        rejectsExecution(fn()=>Execution::complete(91001,92001,$run,'wrong',$claim['fence'],'reply'),'STALE_WORKER');
        rejectsExecution(fn()=>Execution::complete(91001,92001,$run,$claim['token'],$claim['fence']+1,'reply'),'STALE_WORKER');
        agentCheck(!Execution::expire(91001,92001,$run),$scenario.' live lease does not expire');
        if ($scenario==='success') {
            agentCheck(Execution::complete(91001,92001,$run,$claim['token'],$claim['fence'],'测试回复'),'reply persisted successfully');
            agentCheck(Execution::complete(91001,92001,$run,$claim['token'],$claim['fence'],'测试回复'),'duplicate completion is stable');
            rejectsExecution(fn()=>Execution::complete(91001,92001,$run,$claim['token'],$claim['fence'],'different'),'REPLY_CONFLICT');
            $messages=Store::messages(91001,92001,$canvas,$thread);
            agentCheck(count($messages)===2 && $messages[1]['sequence']===2 && $messages[1]['role']==='assistant','assistant message sequence follows user');
            agentCheck((int)Db::name(Store::PREFIX.'thread')->where('id',$thread)->value('active_run_id')===0,'successful reply releases conversation');
            agentCheck(array_column(Store::events(91001,92001,$canvas,$thread),'kind')===['run.queued','run.running','run.succeeded'],'ordered durable lifecycle events');
            agentCheck(Db::name(Store::PREFIX.'outbox')->where('run_id',$run)->value('state')==='done','successful outbox is complete');
            $next=Store::enqueue(91001,92001,$canvas,$thread,['request_key'=>'success-next','content'=>'再问','base_revision'=>0],['settings'=>['reasoning_model'=>'isolated-next'],'skill'=>[]]);
            agentCheck($next['message_sequence']===3,'next turn continues same thread sequence');
        } else {
            if ($scenario==='unknown') {
                Execution::unknown(91001,92001,$run,$claim['token'],$claim['fence']);
                Execution::unknown(91001,92001,$run,$claim['token'],$claim['fence']);
            } else {
                Db::name(Store::PREFIX.'outbox')->where('run_id',$run)->update(['lease_until'=>time()-1]);
                if ($scenario==='expire') agentCheck(Execution::expire(91001,92001,$run),'expired lease is quarantined');
            }
            agentCheck(!Execution::complete(91001,92001,$run,$claim['token'],$claim['fence'],'迟到回复'),'uncertain reply does not fabricate success');
            agentCheck(!Execution::complete(91001,92001,$run,$claim['token'],$claim['fence'],'迟到回复'),'duplicate late reply remains uncertain');
            rejectsExecution(fn()=>Execution::complete(91001,92001,$run,$claim['token'],$claim['fence'],'changed'),'REPLY_CONFLICT');
            agentCheck(Execution::claim(91001,92001,$run)===null,'uncertain outcome is never automatically resubmitted');
            agentCheck(Db::name(Store::PREFIX.'run')->where('id',$run)->value('status')==='needs_reconciliation','uncertain run retains explicit status');
            agentCheck(count(Store::messages(91001,92001,$canvas,$thread))===1,'uncertain reply not published as assistant success');
            agentCheck(Db::name(Store::PREFIX.'event')->where(['run_id'=>$run,'kind'=>'run.late_reply'])->count()===1,'late reply evidence retained exactly once');
            agentCheck((int)Db::name(Store::PREFIX.'thread')->where('id',$thread)->value('active_run_id')===$run,'unresolved run keeps conversation fenced');
        }
        agentCheck((int)Db::name(Store::PREFIX.'outbox')->where('run_id',$run)->value('attempts')===1,$scenario.' has one dispatch attempt');
    }
    $thread=Store::create(91001,92001,$canvas,'authorize-once')['id'];
    $ack=Store::enqueue(91001,92001,$canvas,$thread,['request_key'=>'authorize-once','content'=>'one handoff','base_revision'=>0],['settings'=>[],'skill'=>[]]);
    $claim=Execution::claim(91001,92001,$ack['run_id']);
    agentCheck(Execution::authorizeSubmission(91001,92001,$ack['run_id'],$claim['token'],$claim['fence'])==='authorized','valid lease authorizes one durable handoff');
    agentCheck(Execution::authorizeSubmission(91001,92001,$ack['run_id'],$claim['token'],$claim['fence'])==='not_claimed','same claim cannot authorize second handoff');
    Db::name(Store::PREFIX.'outbox')->where('run_id',$ack['run_id'])->update(['lease_until'=>time()-1]);
    agentCheck(Execution::expire(91001,92001,$ack['run_id']),'submitting handoff expires into reconciliation');
    agentCheck(Execution::authorizeSubmission(91001,92001,$ack['run_id'],$claim['token'],$claim['fence'])==='needs_reconciliation','expired handoff cannot authorize a retry');
    agentCheck(Db::name(Graph::TABLE)->where('id',$canvas)->find()===$before,'conversation lifecycle never mutates canvas');
    agentCheck(Db::name('aigc_short_drama_canvas_run')->where('canvas_id',$canvas)->count()===0,'conversation lifecycle creates no media run');
} finally {Db::rollback();}
echo "NOT_RUN real Provider, billing, worker process/kill recovery, HTTP, frontend and reconciliation settlement\n";
