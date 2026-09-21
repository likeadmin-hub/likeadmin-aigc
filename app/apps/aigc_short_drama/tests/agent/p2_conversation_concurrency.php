<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use think\facade\Db;
use app\common\service\app\aigc_short_drama\ShortDramaCanvasService as Canvas;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationStore as Store;
use app\common\service\app\aigc_short_drama\canvas_agent\GraphService as Graph;

if (($argv[1]??'')==='worker') {
    echo "READY\n";
    if (trim((string)fgets(STDIN))!=='GO') throw new RuntimeException('Missing barrier');
    try {
        $thread=Store::create(91001,92001,(int)$argv[2],'concurrent-thread');
        $ack=Store::enqueue(91001,92001,(int)$argv[2],$thread['id'],['request_key'=>$argv[3],'content'=>$argv[4],'base_revision'=>0],['settings'=>['reasoning_model'=>'isolated-model'],'skill'=>[]]);
        echo json_encode(['ok'=>$ack],JSON_THROW_ON_ERROR),PHP_EOL;
    } catch (RuntimeException $error) {
        if (!in_array($error->getMessage(),['THREAD_BUSY','IDEMPOTENCY_CONFLICT'],true)) throw $error;
        echo json_encode(['error'=>$error->getMessage()]),PHP_EOL;
    }
    exit(0);
}
function raceConversation(int $canvas,array $requests): array {
    $children=[];
    try {
        foreach ($requests as [$key,$content]) {
            $process=proc_open([PHP_BINARY,__FILE__,'worker',(string)$canvas,$key,$content],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            if (!is_resource($process)) throw new RuntimeException('Cannot start child');
            stream_set_timeout($pipes[1],20);$children[]=[$process,$pipes];
        }
        foreach ($children as [, $pipes]) if (trim((string)fgets($pipes[1]))!=='READY') throw new RuntimeException('Child readiness failed');
        foreach ($children as [, $pipes]) fwrite($pipes[0],"GO\n");
        $results=[];
        foreach ($children as [, $pipes]) {
            $line=fgets($pipes[1]);
            if (!$line) throw new RuntimeException('Child returned no result');
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
if (Db::name('aigc_short_drama_config')->where('tenant_id',91001)->find()) throw new RuntimeException('Existing test config must be reviewed');
$canvases=[];$config=0;
try {
    $config=Db::name('aigc_short_drama_config')->insertGetId(['tenant_id'=>91001,'config_json'=>'{"canvas_agent":{"enabled":true}}','status'=>1,'create_time'=>time(),'update_time'=>time()]);
    foreach (['same','conflict','busy'] as $scenario) {
        $canvas=Canvas::create(91001,92001,['title'=>'P2 concurrent '.$scenario])['id'];$canvases[]=$canvas;
        $requests=$scenario==='same'?array_fill(0,10,['same','question']):($scenario==='conflict'?[['same','one'],['same','two']]:[['one','question'],['two','question']]);
        $results=raceConversation($canvas,$requests);
        if ($scenario==='same') {
            agentCheck(isset($results[0]['ok']) && count(array_filter($results,static fn($r)=>$r===$results[0]))===10,'ten independent sends return identical acknowledgement');
        } else {
            agentCheck(count(array_filter($results,static fn($r)=>isset($r['ok'])))===1,$scenario.' has one winner');
            $error=$scenario==='conflict'?'IDEMPOTENCY_CONFLICT':'THREAD_BUSY';
            agentCheck(count(array_filter($results,static fn($r)=>($r['error']??'')===$error))===1,$scenario.' loser explicitly rejected');
        }
        foreach (['thread','message','run','event','outbox'] as $kind) agentCheck(Db::name(Store::PREFIX.$kind)->where('canvas_id',$canvas)->count()===1,$scenario.' exactly one '.$kind);
        $document=Db::name(Graph::TABLE)->where('id',$canvas)->find();
        agentCheck((int)$document['graph_revision']===0 && json_decode($document['nodes_json'],true)===[],$scenario.' leaves canvas empty and unchanged');
    }
} finally {
    foreach ($canvases as $canvas) {
        foreach (['outbox','event','message','run','thread'] as $kind) Db::name(Store::PREFIX.$kind)->where(['tenant_id'=>91001,'user_id'=>92001,'canvas_id'=>$canvas])->delete();
        Db::name(Graph::TABLE)->where(['id'=>$canvas,'tenant_id'=>91001,'user_id'=>92001])->delete();
    }
    if ($config) Db::name('aigc_short_drama_config')->where(['id'=>$config,'tenant_id'=>91001])->delete();
}
echo "NOT_RUN HTTP concurrent requests and Provider dispatch; independent database connections verified\n";
