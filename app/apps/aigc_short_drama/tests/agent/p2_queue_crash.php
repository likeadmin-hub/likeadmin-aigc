<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use think\facade\Db;
use app\common\service\app\aigc_short_drama\ShortDramaCanvasService as Canvas;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationStore as Store;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationWorker as Worker;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationQueue as Queue;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationProviderInterface;

function queueCrashBarrier(string $phase,string $wanted): void {
    if ($phase!==$wanted) return;
    echo "READY\n";flush();fgets(STDIN);
    throw new RuntimeException('Owned crash child must be terminated');
}
final class QueueFixtureProvider implements ConversationProviderInterface {
    public function __construct(private string $phase='resume') {}
    public function preflight(int $tenant,int $user,array $request): void {queueCrashBarrier($this->phase,'claimed');}
    public function generate(int $tenant,int $user,array $request): array {
        queueCrashBarrier($this->phase,'handoff');
        $run=Db::name(Store::PREFIX.'run')->where(['id'=>$request['run_id'],'tenant_id'=>$tenant,'user_id'=>$user])->find();
        Db::name('aigc_short_drama_test_provider_receipt')->insert(['tenant_id'=>$tenant,'user_id'=>$user,'canvas_id'=>$run['canvas_id'],'intent_id'=>$request['run_id'],'create_time'=>time()]);
        queueCrashBarrier($this->phase,'received');
        return ['content'=>'Isolated recovered reply','tool_calls'=>[]];
    }
}
if (($argv[1]??'')==='worker') {
    $canvas=(int)$argv[2];$phase=$argv[3];
    $thread=Store::create(91001,92001,$canvas,'crash-thread')['id'];
    $ack=Store::enqueue(91001,92001,$canvas,$thread,['request_key'=>'crash-send','content'=>'Frozen question','base_revision'=>0],['settings'=>['reasoning_model'=>['id'=>'isolated-model']],'skill'=>[]]);
    queueCrashBarrier($phase,'queued');
    Worker::process(91001,92001,$ack['run_id'],new QueueFixtureProvider($phase));
    queueCrashBarrier($phase,'completed');
    exit(0);
}
function killQueueChild(int $canvas,string $phase): void {
    $process=proc_open([PHP_BINARY,__FILE__,'worker',(string)$canvas,$phase],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start owned child');
    try {
        stream_set_timeout($pipes[1],15);
        if (trim((string)fgets($pipes[1]))!=='READY') throw new RuntimeException('Child did not reach crash barrier');
        if (!proc_terminate($process,9)) throw new RuntimeException('Cannot stop owned synthetic child');
    } finally {foreach ($pipes as $pipe) fclose($pipe);proc_close($process);}
}
if (Db::name('aigc_short_drama_config')->where('tenant_id',91001)->count() || Db::name(Store::PREFIX.'outbox')->where('tenant_id',91001)->count()) throw new RuntimeException('Existing isolated queue fixture must be reviewed');
$owned=[];$config=0;
try {
    $config=Db::name('aigc_short_drama_config')->insertGetId(['tenant_id'=>91001,'config_json'=>'{"canvas_agent":{"enabled":true}}','status'=>1]);
    foreach (['queued','claimed','handoff','received','completed'] as $phase) {
        $canvas=(int)Canvas::create(91001,92001,['title'=>'P2 exact process boundary '.$phase])['id'];$owned[]=$canvas;
        killQueueChild($canvas,$phase);
        $run=Db::name(Store::PREFIX.'run')->where('canvas_id',$canvas)->find();
        agentCheck($run!==null && Db::name(Store::PREFIX.'outbox')->where('run_id',$run['id'])->count()===1,$phase.' committed run/outbox survive SIGKILL');
        if (in_array($phase,['claimed','handoff','received'],true)) Db::name(Store::PREFIX.'outbox')->where('run_id',$run['id'])->update(['lease_until'=>time()-1]);
        $scan=Queue::tick(91001,new QueueFixtureProvider());
        $status=Db::name(Store::PREFIX.'run')->where('id',$run['id'])->value('status');
        agentCheck($status===(in_array($phase,['queued','completed'],true)?'success':'needs_reconciliation'),$phase.' restart recovers only known-safe state');
        $receipts=Db::name('aigc_short_drama_test_provider_receipt')->where('canvas_id',$canvas)->count();
        agentCheck($receipts===(in_array($phase,['queued','received','completed'],true)?1:0),$phase.' restart does not duplicate upstream acceptance');
        agentCheck(Queue::tick(91001,new QueueFixtureProvider())['scanned']===0,$phase.' later scans never requeue completed/uncertain work');
        agentCheck(Db::name(Store::PREFIX.'message')->where('canvas_id',$canvas)->count()===(in_array($phase,['queued','completed'],true)?2:1),$phase.' only confirmed replies become messages');
    }
    $canvas=(int)Canvas::create(91001,92001,['title'=>'P2 scan cursor and gate'])['id'];$owned[]=$canvas;
    for ($i=0;$i<3;$i++) {
        $thread=Store::create(91001,92001,$canvas,'cursor-'.$i)['id'];
        Store::enqueue(91001,92001,$canvas,$thread,['request_key'=>'cursor-'.$i,'content'=>'Cursor question','base_revision'=>0],['settings'=>[],'skill'=>[]]);
    }
    Db::name('aigc_short_drama_config')->where('id',$config)->update(['config_json'=>'{}']);
    $disabled=Queue::tick(91001,new QueueFixtureProvider(),0,1);
    agentCheck($disabled['scanned']===1 && $disabled['items'][0]['state']==='deferred_disabled','disabled tenant is deferred without Provider call');
    agentCheck(Db::name('aigc_short_drama_test_provider_receipt')->where('canvas_id',$canvas)->count()===0,'disabled scan has zero provider acceptances');
    Db::name('aigc_short_drama_config')->where('id',$config)->update(['config_json'=>'{"canvas_agent":{"enabled":true}}']);
    $next=Queue::tick(91001,new QueueFixtureProvider(),$disabled['cursor'],1);
    agentCheck($next['scanned']===1 && $next['cursor']>$disabled['cursor'],'bounded cursor advances past deferred job');
    $last=Queue::tick(91001,new QueueFixtureProvider(),$next['cursor'],1);
    agentCheck($last['scanned']===1 && $last['cursor']>$next['cursor'],'next batch uses durable outbox cursor');
    agentCheck(Queue::tick(91002,new QueueFixtureProvider())['scanned']===0,'tenant-scoped scan never sees another tenant queue');
    agentCheck(Queue::tick(91001,new QueueFixtureProvider(),0,1)['items'][0]['state']==='success','reset cursor revisits formerly deferred pending job');
} finally {
    foreach ($owned as $canvas) {
        foreach (['outbox','event','message','run','thread'] as $kind) Db::name(Store::PREFIX.$kind)->where(['canvas_id'=>$canvas,'tenant_id'=>91001,'user_id'=>92001])->delete();
        Db::name('aigc_short_drama_test_provider_receipt')->where(['canvas_id'=>$canvas,'tenant_id'=>91001,'user_id'=>92001])->delete();
        Db::name('aigc_short_drama_canvas')->where(['id'=>$canvas,'tenant_id'=>91001,'user_id'=>92001])->delete();
    }
    if ($config) Db::name('aigc_short_drama_config')->where(['id'=>$config,'tenant_id'=>91001])->delete();
}
echo "NOT_RUN real Provider/billing, production supervisor registration or live Worker interruption\n";
