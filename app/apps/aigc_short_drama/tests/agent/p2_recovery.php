<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use think\facade\Db;
use app\common\service\app\aigc_short_drama\ShortDramaCanvasService as Canvas;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationStore as Store;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationExecution as Execution;
function recoveryReject(callable $action,string $code): void {
    try {$action();} catch (RuntimeException $error) {agentCheck($error->getMessage()===$code,$code);return;}
    throw new RuntimeException('Expected '.$code);
}
Db::startTrans();
try {
    Db::name('aigc_short_drama_config')->insert(['tenant_id'=>91001,'config_json'=>'{"canvas_agent":{"enabled":true}}','status'=>1]);
    $canvas=(int)Canvas::create(91001,92001,['title'=>'P2 recovery'])['id'];
    foreach (['queued','running','success','failed','canceled','needs_reconciliation'] as $status) {
        $thread=Store::create(91001,92001,$canvas,$status)['id'];
        $ack=Store::enqueue(91001,92001,$canvas,$thread,['request_key'=>$status,'content'=>'恢复，不重复发送','base_revision'=>0],['settings'=>[],'skill'=>[]]);
        $run=$ack['run_id'];
        if ($status==='canceled') Execution::stop(91001,92001,$canvas,$thread,$run);
        elseif ($status!=='queued') {
            $claim=Execution::claim(91001,92001,$run);
            if ($status==='success') Execution::complete(91001,92001,$run,$claim['token'],$claim['fence'],'reply');
            if ($status==='failed') Execution::rejectBeforeSubmit(91001,92001,$run,$claim['token'],$claim['fence']);
            if ($status==='needs_reconciliation') Execution::unknown(91001,92001,$run,$claim['token'],$claim['fence']);
        }
        $before=[];
        foreach (['thread','message','run','event','outbox'] as $table) $before[$table]=Db::name(Store::PREFIX.$table)->where('canvas_id',$canvas)->order('id')->select()->toArray();
        $view=Store::run(91001,92001,$canvas,$thread,$run);
        agentCheck($view['status']===$status && $view['id']===$run,$status.' is restored from durable record');
        agentCheck($view['can_stop']===in_array($status,['queued','running'],true),$status.' stop affordance follows durable state');
        for ($i=0;$i<10;$i++) agentCheck(Store::run(91001,92001,$canvas,$thread,$run)===$view,$status.' repeated read '.$i.' stable');
        $after=[];
        foreach (array_keys($before) as $table) $after[$table]=Db::name(Store::PREFIX.$table)->where('canvas_id',$canvas)->order('id')->select()->toArray();
        agentCheck($before===$after,$status.' refresh creates no message, run, event, claim or update');
        if ($status==='failed') {
            Db::name(Store::PREFIX.'run')->where('id',$run)->update(['error_code'=>'private provider failure with secret']);
            agentCheck(Store::run(91001,92001,$canvas,$thread,$run)['error_code']==='RUN_FAILED','unknown diagnostic is safely mapped');
        }
    }
    recoveryReject(fn()=>Store::run(91002,92001,$canvas,$thread,$run),'CANVAS_NOT_FOUND');
    recoveryReject(fn()=>Store::run(91001,92002,$canvas,$thread,$run),'CANVAS_NOT_FOUND');
    Db::name(Store::PREFIX.'run')->where('id',$run)->update(['delete_time'=>time()]);
    recoveryReject(fn()=>Store::run(91001,92001,$canvas,$thread,$run),'RUN_NOT_FOUND');
    Db::name(Store::PREFIX.'run')->where('id',$run)->update(['delete_time'=>0]);
    Db::name(Store::PREFIX.'thread')->where('id',$thread)->update(['delete_time'=>time()]);
    recoveryReject(fn()=>Store::run(91001,92001,$canvas,$thread,$run),'THREAD_NOT_FOUND');
    Db::name('aigc_short_drama_canvas')->where('id',$canvas)->update(['delete_time'=>time()]);
    recoveryReject(fn()=>Store::run(91001,92001,$canvas,$thread,$run),'CANVAS_NOT_FOUND');
} finally {Db::rollback();}
echo "NOT_RUN browser refresh/polling, real Provider and billing; read-only state restoration only\n";
