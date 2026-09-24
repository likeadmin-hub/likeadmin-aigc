<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use think\facade\Db;
use app\common\service\app\aigc_short_drama\ShortDramaCanvasService as Canvas;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationStore as Store;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationExecution as Execution;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationWorker as Worker;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationProviderInterface;
function rejectsStop(callable $action): void {
    try {$action();} catch (RuntimeException $error) {agentCheck($error->getMessage()==='RUN_NOT_FOUND','foreign scope cannot stop run');return;}
    throw new RuntimeException('Expected RUN_NOT_FOUND');
}
Db::startTrans();
try {
    Db::name('aigc_short_drama_config')->insert(['tenant_id'=>91001,'config_json'=>'{"canvas_agent":{"enabled":true}}','status'=>1]);
    $canvas=Canvas::create(91001,92001,['title'=>'P2 stop boundary'])['id'];
    foreach (['queued','processing','submitting','success','preflight_stop','preflight_stop_throw'] as $phase) {
        $thread=Store::create(91001,92001,$canvas,$phase)['id'];
        $run=Store::enqueue(91001,92001,$canvas,$thread,['request_key'=>$phase,'content'=>'stop fixture','base_revision'=>0],['settings'=>[],'skill'=>[]])['run_id'];
        rejectsStop(fn()=>Execution::stop(91002,92001,$canvas,$thread,$run));
        rejectsStop(fn()=>Execution::stop(91001,92002,$canvas,$thread,$run));
        rejectsStop(fn()=>Execution::stop(91001,92001,$canvas+1,$thread,$run));
        rejectsStop(fn()=>Execution::stop(91001,92001,$canvas,$thread+1,$run));
        if (str_starts_with($phase,'preflight_stop')) {
            $provider=new class($canvas,$thread,$phase) implements ConversationProviderInterface {
                public int $calls=0;
                public function __construct(private int $canvas,private int $thread,private string $phase) {}
                public function preflight(int $tenant,int $user,array $request): void {
                    Execution::stop($tenant,$user,$this->canvas,$this->thread,$request['run_id']);
                    if ($this->phase==='preflight_stop_throw') throw new RuntimeException('test preflight error');
                }
                public function generate(int $tenant,int $user,array $request): array {$this->calls++;return ['content'=>'must not be called'];}
            };
            Worker::process(91001,92001,$run,$provider);
            agentCheck($provider->calls===0,$phase.' fences provider submission');
        } elseif ($phase!=='queued') {
            $claim=Execution::claim(91001,92001,$run);
            if (in_array($phase,['submitting','success'],true)) Execution::authorizeSubmission(91001,92001,$run,$claim['token'],$claim['fence']);
            if ($phase==='success') Execution::complete(91001,92001,$run,$claim['token'],$claim['fence'],'already completed');
        }
        $first=Execution::stop(91001,92001,$canvas,$thread,$run);
        for ($i=0;$i<5;$i++) agentCheck(Execution::stop(91001,92001,$canvas,$thread,$run)===$first,$phase.' repeat stop '.$i);
        if ($phase==='submitting') {
            agentCheck($first['status']==='needs_reconciliation' && !$first['cancellation_confirmed'],'submitted request never claims confirmed upstream cancellation');
            agentCheck(Db::name(Store::PREFIX.'event')->where(['run_id'=>$run,'kind'=>'run.stop_requested'])->count()===1,'one durable stop request event');
            agentCheck(!Execution::complete(91001,92001,$run,$claim['token'],$claim['fence'],'late result'),'stop retains late result without publishing success');
            agentCheck((int)Db::name(Store::PREFIX.'thread')->where('id',$thread)->value('active_run_id')===$run,'unknown upstream result keeps conversation fenced');
        } elseif ($phase==='success') {
            agentCheck($first['status']==='success' && !$first['cancellation_confirmed'],'stop does not rewrite completed success');
            agentCheck(count(Store::messages(91001,92001,$canvas,$thread))===2,'completed reply remains readable');
        } else {
            agentCheck($first['status']==='canceled' && $first['cancellation_confirmed'],$phase.' is canceled before any provider submission');
            agentCheck((int)Db::name(Store::PREFIX.'thread')->where('id',$thread)->value('active_run_id')===0,$phase.' releases conversation');
            agentCheck(Execution::claim(91001,92001,$run)===null,$phase.' canceled run never reclaims');
        }
    }
    $thread=Store::create(91001,92001,$canvas,'disabled-stop')['id'];
    $run=Store::enqueue(91001,92001,$canvas,$thread,['request_key'=>'disabled-stop','content'=>'stop fixture','base_revision'=>0],['settings'=>[],'skill'=>[]])['run_id'];
    Db::name('aigc_short_drama_config')->where('tenant_id',91001)->update(['config_json'=>'{}']);
    agentCheck(Execution::stop(91001,92001,$canvas,$thread,$run)['status']==='canceled','owner can stop pending run after Agent is disabled');
    agentCheck(Db::name('aigc_short_drama_canvas_run')->where('canvas_id',$canvas)->count()===0,'stop never creates media work');
} finally {Db::rollback();}
echo "NOT_RUN upstream cancel/refund, frontend stop control and independent stop-vs-submit race\n";
