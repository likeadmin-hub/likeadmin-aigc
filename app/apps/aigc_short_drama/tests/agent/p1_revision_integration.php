<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use think\facade\Db;
use app\common\service\app\aigc_short_drama\ShortDramaCanvasService as Canvas;
use app\common\service\app\aigc_short_drama\ShortDramaCanvasPosterJobService as Posters;
use app\common\service\app\aigc_short_drama\canvas_agent\GraphService as Graph;
function revisionConflict(callable $call, string $label): void {
    try {$call();} catch (RuntimeException $error) {
        agentCheck(strpos($error->getMessage(),'VERSION_CONFLICT')===0,$label); return;
    }
    throw new RuntimeException('Expected version conflict: '.$label);
}
Db::startTrans();
try {
    $doc=Canvas::create(91001,92001,['title'=>'P1 integrated revisions']); $id=$doc['id'];
    agentCheck($doc['graph_revision']===0 && $doc['schema_version']===1,'new document keeps legacy schema until first versioned write');
    $runId=Db::name('aigc_short_drama_canvas_run')->insertGetId(['tenant_id'=>91001,'user_id'=>92001,'canvas_id'=>$id,'node_id'=>'1','node_type'=>'video','status'=>'running','request_json'=>'{}','result_json'=>'{}']);
    $node=['id'=>1,'type'=>'video','x'=>10,'y'=>20,'metadata'=>['video_url'=>'uploads/fixture/video.mp4','poster_url'=>'uploads/fixture/old.jpg','canvasRunId'=>$runId]];
    $doc=Canvas::save(91001,92001,['id'=>$id,'expected_revision'=>'0','nodes'=>[$node]]);
    agentCheck($doc['graph_revision']===1 && $doc['schema_version']===2,'actual save advances revision and enables concurrency contract');
    revisionConflict(fn()=>Canvas::save(91001,92001,['id'=>$id,'nodes'=>[$node]]),'old client cannot bypass CAS on versioned document');
    revisionConflict(fn()=>Graph::patch(91001,92001,$id,['request_key'=>'stale-patch','expected_revision'=>0,'operations'=>[['op'=>'remove_nodes','node_ids'=>[1]]]]),'ordinary save invalidates old patch base');
    $poster=new ReflectionMethod(Posters::class,'updateCanvasNodePoster');$poster->setAccessible(true);
    $poster->invoke(null,['tenant_id'=>91001,'user_id'=>92001,'canvas_id'=>$id,'node_id'=>'1','video_uri'=>'uploads/fixture/video.mp4'],['uri'=>'uploads/fixture/new.jpg','storage_scope'=>'platform','storage_engine'=>'local','storage_domain'=>'']);
    $doc=Canvas::current(91001,92001,$id);
    agentCheck($doc['graph_revision']===2 && $doc['nodes'][0]['metadata']['poster_uri']==='uploads/fixture/new.jpg','poster projection advances same graph revision');
    revisionConflict(fn()=>Canvas::save(91001,92001,['id'=>$id,'expected_revision'=>1,'nodes'=>[$node]]),'poster projection rejects stale ordinary save');
    $node=$doc['nodes'][0];$node['x']=777;
    $doc=Canvas::save(91001,92001,['id'=>$id,'expected_revision'=>2,'nodes'=>[$node]]);
    agentCheck($doc['graph_revision']===3 && $doc['nodes'][0]['x']===777 && $doc['nodes'][0]['metadata']['poster_uri']==='uploads/fixture/new.jpg','explicit current-base movement keeps background poster');
    $project=new ReflectionMethod(Canvas::class,'projectVideoRunToCanvas');$project->setAccessible(true);
    $run=['id'=>$runId,'tenant_id'=>91001,'user_id'=>92001,'canvas_id'=>$id,'node_id'=>'1'];
    $result=['url'=>'uploads/fixture/new-video.mp4','poster_url'=>'uploads/fixture/final.jpg'];
    $project->invoke(null,$run,$result);
    $doc=Canvas::current(91001,92001,$id);
    agentCheck($doc['graph_revision']===4 && $doc['nodes'][0]['x']===777,'video result advances same revision and preserves user layout');
    $run['id']=$runId+999;$project->invoke(null,$run,['url'=>'uploads/fixture/late-video.mp4','poster_url'=>'uploads/fixture/late.jpg']);
    $doc=Canvas::current(91001,92001,$id);
    agentCheck($doc['graph_revision']===4 && $doc['nodes'][0]['metadata']['video_url']==='uploads/fixture/new-video.mp4','old run does not change current video or graph revision');
    Db::name('aigc_short_drama_canvas_run')->insert(['tenant_id'=>91001,'user_id'=>92001,'canvas_id'=>$id,'node_id'=>'2','node_type'=>'text','status'=>'success','request_json'=>'{}','result_json'=>'{}']);
    $doc=Canvas::current(91001,92001,$id);
    agentCheck($doc['graph_revision']===5 && count($doc['nodes'])===2,'history recovery advances same graph revision');
    $doc=Graph::patch(91001,92001,$id,['request_key'=>'integrated-move','expected_revision'=>5,'operations'=>[['op'=>'move_nodes','nodes'=>[['id'=>1,'x'=>888,'expected_layout_revision'=>$doc['nodes'][0]['metadata']['layout_revision']]]]]]);
    agentCheck($doc['graph_revision']===6 && $doc['nodes'][0]['x']===888,'patch continues from actual writer revisions');
    revisionConflict(fn()=>Canvas::save(91001,92001,['id'=>$id,'expected_revision'=>5,'nodes'=>[$node]]),'patch invalidates old whole-document base');
} finally {Db::rollback();}
echo "NOT_RUN production migration, protected legacy metadata, generation idempotency and full callback lifecycle\n";
