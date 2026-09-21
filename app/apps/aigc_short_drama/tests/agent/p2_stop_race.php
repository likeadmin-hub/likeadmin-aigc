<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use think\facade\Db;
use app\common\service\app\aigc_short_drama\ShortDramaCanvasService as Canvas;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationStore as Store;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationExecution as Execution;
if (($argv[1]??'')==='worker') {
    [$script,$mode,$action,$canvas,$thread,$run,$token,$fence]=$argv;
    echo "READY\n";flush();
    if (trim((string)fgets(STDIN))!=='GO') throw new RuntimeException('Missing race barrier');
    if ($action==='stop') $result=Execution::stop(91001,92001,(int)$canvas,(int)$thread,(int)$run);
    elseif ($action==='complete') $result=['completed'=>Execution::complete(91001,92001,(int)$run,$token,(int)$fence,'same concurrent reply')];
    else {
        $state=Execution::authorizeSubmission(91001,92001,(int)$run,$token,(int)$fence);
        if ($state==='authorized') Db::name('aigc_short_drama_test_provider_receipt')->insert(['tenant_id'=>91001,'user_id'=>92001,'canvas_id'=>(int)$canvas,'intent_id'=>(int)$run,'create_time'=>time()]);
        $result=['permit'=>$state];
    }
    echo json_encode($result,JSON_THROW_ON_ERROR),PHP_EOL;exit(0);
}
function stopRace(int $canvas,int $thread,int $run,array $claim,array $actions=['stop','permit']): array {
    $children=[];
    try {
        foreach ($actions as $action) {
            $process=proc_open([PHP_BINARY,__FILE__,'worker',$action,(string)$canvas,(string)$thread,(string)$run,$claim['token'],(string)$claim['fence']],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            if (!is_resource($process)) throw new RuntimeException('Cannot start owned child');
            stream_set_timeout($pipes[1],15);$children[]=[$process,$pipes];
        }
        foreach ($children as [, $pipes]) if (trim((string)fgets($pipes[1]))!=='READY') throw new RuntimeException('Child readiness failed');
        foreach ($children as [, $pipes]) fwrite($pipes[0],"GO\n");
        $results=[];
        foreach ($children as [$process, $pipes]) {
            $line=fgets($pipes[1]);
            if ($line===false) {
                stream_set_blocking($pipes[2],false);
                throw new RuntimeException('Race child returned no result: '.stream_get_contents($pipes[2]));
            }
            $results[]=json_decode($line,true,512,JSON_THROW_ON_ERROR);
        }
        return $results;
    } finally {
        foreach ($children as [$process,$pipes]) {
            foreach ($pipes as $pipe) fclose($pipe);
            if (proc_get_status($process)['running']) proc_terminate($process);
            proc_close($process);
        }
    }
}
if (Db::name('aigc_short_drama_config')->where('tenant_id',91001)->count()) throw new RuntimeException('Existing isolated config');
$config=0;$owned=[];
try {
    $config=Db::name('aigc_short_drama_config')->insertGetId(['tenant_id'=>91001,'config_json'=>'{"canvas_agent":{"enabled":true}}','status'=>1]);
    for ($i=0;$i<10;$i++) {
        $canvas=(int)Canvas::create(91001,92001,['title'=>'P2 independent stop race'])['id'];$owned[]=$canvas;
        $thread=Store::create(91001,92001,$canvas,'thread')['id'];
        $run=Store::enqueue(91001,92001,$canvas,$thread,['request_key'=>'send','content'=>'race','base_revision'=>0],['settings'=>[],'skill'=>[]])['run_id'];
        $claim=Execution::claim(91001,92001,$run);
        [$stop,$permit]=stopRace($canvas,$thread,$run,$claim);
        $submitted=$permit['permit']==='authorized';
        $status=Db::name(Store::PREFIX.'run')->where('id',$run)->value('status');
        agentCheck($status===($submitted?'needs_reconciliation':'canceled'),'race '.$i.' preserves winner boundary');
        agentCheck($stop['cancellation_confirmed']===!$submitted,'race '.$i.' never falsely confirms upstream cancellation');
        agentCheck(Db::name('aigc_short_drama_test_provider_receipt')->where('canvas_id',$canvas)->count()===($submitted?1:0),'race '.$i.' has at most one authorized provider acceptance');
        agentCheck(Execution::claim(91001,92001,$run)===null,'race '.$i.' cannot reclaim stopped run');
    }
    foreach (['stop','complete','late'] as $scenario) {
        $canvas=(int)Canvas::create(91001,92001,['title'=>'P2 concurrent replay'])['id'];$owned[]=$canvas;
        $thread=Store::create(91001,92001,$canvas,'thread')['id'];
        $run=Store::enqueue(91001,92001,$canvas,$thread,['request_key'=>'send','content'=>'replay','base_revision'=>0],['settings'=>[],'skill'=>[]])['run_id'];
        $claim=Execution::claim(91001,92001,$run);
        Execution::authorizeSubmission(91001,92001,$run,$claim['token'],$claim['fence']);
        if ($scenario==='late') Execution::unknown(91001,92001,$run,$claim['token'],$claim['fence']);
        $action=$scenario==='stop'?'stop':'complete';
        [$first,$second]=stopRace($canvas,$thread,$run,$claim,[$action,$action]);
        agentCheck($first===$second,$scenario.' concurrent replay has stable response');
        $status=Db::name(Store::PREFIX.'run')->where('id',$run)->value('status');
        agentCheck($status===($scenario==='complete'?'success':'needs_reconciliation'),$scenario.' concurrent replay preserves state');
        $kind=['stop'=>'run.stop_requested','complete'=>'run.succeeded','late'=>'run.late_reply'][$scenario];
        agentCheck(Db::name(Store::PREFIX.'event')->where(['run_id'=>$run,'kind'=>$kind])->count()===1,$scenario.' concurrent replay emits evidence only once');
        agentCheck(Db::name(Store::PREFIX.'message')->where(['run_id'=>$run,'role'=>'assistant'])->count()===($scenario==='complete'?1:0),$scenario.' concurrent replay never duplicates assistant message');
    }
} finally {
    foreach ($owned as $canvas) {
        foreach (['outbox','event','message','run','thread'] as $kind) Db::name(Store::PREFIX.$kind)->where(['canvas_id'=>$canvas,'tenant_id'=>91001,'user_id'=>92001])->delete();
        Db::name('aigc_short_drama_test_provider_receipt')->where(['canvas_id'=>$canvas,'tenant_id'=>91001,'user_id'=>92001])->delete();
        Db::name('aigc_short_drama_canvas')->where(['id'=>$canvas,'tenant_id'=>91001,'user_id'=>92001])->delete();
    }
    if ($config) Db::name('aigc_short_drama_config')->where(['id'=>$config,'tenant_id'=>91001])->delete();
}
echo "NOT_RUN real provider cancellation/refund; independent processes verify local authorization boundary only\n";
