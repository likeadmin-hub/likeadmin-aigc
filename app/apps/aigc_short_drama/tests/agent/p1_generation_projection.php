<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use think\facade\Db;
use app\common\service\app\aigc_short_drama\ShortDramaCanvasService as Canvas;
use app\common\service\app\aigc_short_drama\canvas_agent\GenerationIntentService as Intent;
function projectedFixture(string $type,string $version): array {
    return $type==='text'?['content'=>'Result '.$version]:['results'=>[['url'=>'uploads/fixture/'.$version,'uri'=>'uploads/fixture/'.$version,'asset_id'=>123,'storage_scope'=>'tenant','storage_engine'=>'local','storage_domain'=>'','poster_url'=>'uploads/fixture/poster.jpg']]];
}
function finishFixture(array $claim,string $type,string $version): void {
    Intent::accepted(91001,92001,(int)$claim['id'],$claim['claim_token'],(int)$claim['fencing_version'],'fixture-'.$version,projectedFixture($type,$version),true);
}
Db::startTrans();
try {
    foreach (['text','image','video','audio'] as $type) {
        $id=Canvas::create(91001,92001,['title'=>'P1 '.$type.' projection'])['id'];
        Canvas::save(91001,92001,['id'=>$id,'nodes'=>[['id'=>1,'type'=>$type,'x'=>10,'y'=>20,'metadata'=>['prompt'=>'original','richContent'=>'<p>Old result</p>']]]]);
        $a=Intent::reserve(91001,92001,$id,'a','1',$type,['prompt'=>'A']);$a=Intent::claim(91001,92001,(int)$a['id']);
        $b=Intent::reserve(91001,92001,$id,'b','1',$type,['prompt'=>'B']);$b=Intent::claim(91001,92001,(int)$b['id']);
        finishFixture($b,$type,'B');
        $nodes=Canvas::current(91001,92001,$id)['nodes'];$nodes[0]['x']=999;
        Canvas::save(91001,92001,['id'=>$id,'nodes'=>$nodes]);
        agentCheck(Intent::projectResult(91001,92001,(int)$b['canvas_run_id']),$type.' projects active completed result');
        $current=Canvas::current(91001,92001,$id);
        $field=$type==='text'?'content':'url';$expected=$type==='text'?'Result B':'uploads/fixture/B';
        agentCheck($current['nodes'][0]['x']===999 && $current['nodes'][0]['metadata'][$field]===$expected,$type.' background result preserves concurrent movement');
        if (in_array($type,['image','video','audio'],true)) agentCheck((int)($current['nodes'][0]['metadata']['asset_id']??0)===123,$type.' projection retains durable output asset identity');
        if ($type==='text') agentCheck($current['nodes'][0]['metadata']['richContent']==='','text generation removes obsolete rich-text preview');
        $revision=$current['graph_revision'];
        agentCheck(!Intent::projectResult(91001,92001,(int)$b['canvas_run_id']) && Canvas::current(91001,92001,$id)['graph_revision']===$revision,$type.' duplicate projection does not change graph revision');
        finishFixture($a,$type,'A');
        agentCheck(!Intent::projectResult(91001,92001,(int)$a['canvas_run_id']) && Canvas::current(91001,92001,$id)['nodes'][0]['metadata'][$field]===$expected,$type.' late first result cannot replace second result');
        agentCheck(Db::name('aigc_short_drama_canvas_run')->where('canvas_id',$id)->where('status','success')->count()===2,$type.' both generations remain in run history');
        $c=Intent::reserve(91001,92001,$id,'c','1',$type,['prompt'=>'C']);$c=Intent::claim(91001,92001,(int)$c['id']);
        $nodes=Canvas::current(91001,92001,$id)['nodes'];$nodes[0]['metadata']['prompt']='user edit while generating';
        Canvas::save(91001,92001,['id'=>$id,'nodes'=>$nodes]);
        finishFixture($c,$type,'C');
        agentCheck(!Intent::projectResult(91001,92001,(int)$c['canvas_run_id']) && Canvas::current(91001,92001,$id)['nodes'][0]['metadata']['prompt']==='user edit while generating',$type.' edited input rejects stale result projection');
        $d=Intent::reserve(91001,92001,$id,'d','1',$type,['prompt'=>'D']);$d=Intent::claim(91001,92001,(int)$d['id']);
        Canvas::save(91001,92001,['id'=>$id,'nodes'=>[],'removed_node_ids'=>['1']]);
        finishFixture($d,$type,'D');
        agentCheck(!Intent::projectResult(91001,92001,(int)$d['canvas_run_id']) && Canvas::current(91001,92001,$id)['nodes']===[],$type.' deleted node never resurrects on late completion');
        agentCheck(Db::name('aigc_short_drama_canvas_run')->where('canvas_id',$id)->where('status','success')->count()===4,$type.' deletion retains all completed run records');
    }
    $id=Canvas::create(91001,92001,['title'=>'P1 missing video poster'])['id'];
    Canvas::save(91001,92001,['id'=>$id,'nodes'=>[['id'=>1,'type'=>'video','metadata'=>[]]]]);
    $intent=Intent::reserve(91001,92001,$id,'poster','1','video',[]);
    $claim=Intent::claim(91001,92001,(int)$intent['id']);
    Intent::accepted(91001,92001,(int)$claim['id'],$claim['claim_token'],(int)$claim['fencing_version'],'fixture-poster',[
        'results'=>[['url'=>'uploads/fixture/video.mp4','uri'=>'uploads/fixture/video.mp4','storage_scope'=>'tenant','storage_engine'=>'local','storage_domain'=>'']],
    ],true);
    Intent::projectResult(91001,92001,(int)$claim['canvas_run_id']);
    Intent::projectResult(91001,92001,(int)$claim['canvas_run_id']);
    $jobs=Db::name('aigc_short_drama_canvas_poster_job')->where(['canvas_id'=>$id,'tenant_id'=>91001,'user_id'=>92001])->select()->toArray();
    agentCheck(count($jobs)===1 && $jobs[0]['status']==='pending','intent video projection queues exactly one existing asynchronous poster job');
    agentCheck($jobs[0]['video_uri']==='uploads/fixture/video.mp4' && $jobs[0]['storage_scope']==='tenant','poster job retains source URI and tenant storage metadata');
    $id=Canvas::create(91001,92001,['title'=>'P1 status projection'])['id'];
    Canvas::save(91001,92001,['id'=>$id,'nodes'=>[['id'=>1,'type'=>'video','x'=>10,'metadata'=>['prompt'=>'fixture']]]]);
    $intent=Intent::reserve(91001,92001,$id,'status','1','video',['prompt'=>'fixture']);
    $claim=Intent::claim(91001,92001,(int)$intent['id']);
    Intent::unknown(91001,92001,(int)$claim['id'],$claim['claim_token'],(int)$claim['fencing_version']);
    $runId=(int)$claim['canvas_run_id'];
    $before=Canvas::current(91001,92001,$id);
    agentCheck(($before['nodes'][0]['metadata']['status']??'')==='queued','unknown run starts with a stale graph status before projection');
    agentCheck((Canvas::runDetail(91001,92001,$runId)['status']??'')==='needs_reconciliation','run detail retains the unknown submission state');
    $after=Canvas::current(91001,92001,$id);
    agentCheck(($after['nodes'][0]['metadata']['status']??'')==='needs_reconciliation' && ($after['nodes'][0]['metadata']['error']??'')!=='','unknown run is visibly held without resubmission');
    agentCheck(!Intent::projectStatus(91001,92001,$runId) && Canvas::current(91001,92001,$id)['graph_revision']===$after['graph_revision'],'duplicate status projection does not change graph revision');
    $next=Intent::reserve(91001,92001,$id,'newer','1','video',['prompt'=>'newer']);
    agentCheck(!Intent::projectStatus(91001,92001,$runId) && (int)Canvas::current(91001,92001,$id)['nodes'][0]['metadata']['active_generation_id']===(int)$next['canvas_run_id'],'older unknown status cannot replace newer generation');
    $id=Canvas::create(91001,92001,['title'=>'P1 failed provider status'])['id'];
    Canvas::save(91001,92001,['id'=>$id,'nodes'=>[['id'=>1,'type'=>'video','metadata'=>['prompt'=>'fixture']]]]);
    $intent=Intent::reserve(91001,92001,$id,'failed','1','video',['prompt'=>'fixture']);
    $claim=Intent::claim(91001,92001,(int)$intent['id']);
    Intent::accepted(91001,92001,(int)$claim['id'],$claim['claim_token'],(int)$claim['fencing_version'],'fixture-task',[],false);
    Db::name('aigc_short_drama_canvas_run')->where('id',$claim['canvas_run_id'])->update(['status'=>'failed','progress'=>0,'error'=>'provider fixture failure']);
    Canvas::runDetail(91001,92001,(int)$claim['canvas_run_id']);
    $failed=Canvas::current(91001,92001,$id)['nodes'][0]['metadata'];
    agentCheck(($failed['status']??'')==='failed' && ($failed['error']??'')==='provider fixture failure','failed provider task status projects to active graph node');
} finally {Db::rollback();}
echo "NOT_RUN real callbacks, asset version selection and browser generation UI; persisted run/projector behavior only\n";
