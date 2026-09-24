<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use think\facade\Db;
use app\common\service\app\aigc_short_drama\ShortDramaCanvasService as Canvas;
use app\common\service\app\aigc_short_drama\canvas_agent\GenerationIntentService as Intent;

if (!Db::query("SHOW TABLES LIKE 'la_aigc_short_drama_test_provider_receipt'")) {
    throw new RuntimeException('Crash fixture table is not installed; refusing to create test canvases');
}

// Kill only the exact synthetic child process created by this test, never a
// live Worker. Independent connections make committed boundaries observable.
function crashBarrier(string $phase,string $wanted): void {
    if ($phase!==$wanted) return;
    echo "READY\n";flush();
    fgets(STDIN);
    throw new RuntimeException('Crash barrier must be terminated, not resumed');
}
if (($argv[1]??'')==='worker') {
    $canvas=(int)$argv[2];$phase=$argv[3];
    $intent=Intent::reserve(91001,92001,$canvas,'crash-key','1','text',['prompt'=>'Frozen crash fixture']);
    crashBarrier($phase,'prepared');
    $claim=Intent::claim(91001,92001,(int)$intent['id']);
    if ($claim) {
        crashBarrier($phase,'claimed');
        Db::name('aigc_short_drama_test_provider_receipt')->insert(['tenant_id'=>91001,'user_id'=>92001,'canvas_id'=>$canvas,'intent_id'=>$intent['id'],'create_time'=>time()]);
        crashBarrier($phase,'received');
        Intent::accepted(91001,92001,(int)$claim['id'],$claim['claim_token'],(int)$claim['fencing_version'],'crash-receipt',['content'=>'Recovered output'],true);
        crashBarrier($phase,'accepted');
    }
    Intent::projectResult(91001,92001,(int)$intent['canvas_run_id']);
    echo "DONE\n";
    exit(0);
}
function crashWorker(int $canvas,string $phase,bool $kill): void {
    $process=proc_open([PHP_BINARY,__FILE__,'worker',(string)$canvas,$phase],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start crash fixture');
    try {
        stream_set_timeout($pipes[1],15);
        $line=trim((string)fgets($pipes[1]));
        if ($line!==($kill?'READY':'DONE')) throw new RuntimeException('Unexpected crash boundary: '.$line);
        if ($kill && !proc_terminate($process,9)) throw new RuntimeException('Cannot terminate owned fixture child');
    } finally {
        foreach ($pipes as $pipe) fclose($pipe);
        $code=proc_close($process);
        if (!$kill && $code!==0) throw new RuntimeException('Recovery child failed');
    }
}
$owned=[];
try {
    foreach (['prepared','claimed','received','accepted'] as $phase) {
        $canvas=(int)Canvas::create(91001,92001,['title'=>'P1 process crash '.$phase])['id'];$owned[]=$canvas;
        Canvas::save(91001,92001,['id'=>$canvas,'nodes'=>[['id'=>1,'type'=>'text','metadata'=>[]]]]);
        crashWorker($canvas,$phase,true);
        $intent=Db::name(Intent::TABLE)->where('canvas_id',$canvas)->find();
        agentCheck($intent!==null && Db::name('aigc_short_drama_canvas_run')->where('canvas_id',$canvas)->count()===1,$phase.' crash retains atomic intent and one logical run');
        if (in_array($phase,['claimed','received'],true)) {
            // Advance only this fixture's deadline; no slow wall-clock sleep.
            Db::name(Intent::TABLE)->where('id',$intent['id'])->update(['lease_until'=>time()-1]);
            agentCheck(Intent::expire(91001,92001,(int)$intent['id']),$phase.' interrupted submit moves to reconciliation');
        }
        crashWorker($canvas,'resume',false);
        $receipts=Db::name('aigc_short_drama_test_provider_receipt')->where('canvas_id',$canvas)->count();
        agentCheck($receipts===($phase==='claimed'?0:1),$phase.' recovery never duplicates external acceptance');
        $intent=Db::name(Intent::TABLE)->where('canvas_id',$canvas)->find();
        $run=Db::name('aigc_short_drama_canvas_run')->where('id',$intent['canvas_run_id'])->find();
        if (in_array($phase,['prepared','accepted'],true)) {
            agentCheck($run['status']==='success' && Canvas::current(91001,92001,$canvas)['nodes'][0]['metadata']['content']==='Recovered output',$phase.' committed input/result resumes into graph safely');
        } else {
            agentCheck($intent['state']==='needs_reconciliation' && $run['status']==='needs_reconciliation',$phase.' uncertain outcome stays unresolved, not falsely retried or refunded');
        }
    }
} finally {
    foreach ($owned as $canvas) {
        foreach (['aigc_short_drama_test_provider_receipt',Intent::TABLE,'aigc_short_drama_canvas_run'] as $table) Db::name($table)->where(['canvas_id'=>$canvas,'tenant_id'=>91001,'user_id'=>92001])->delete();
        Db::name('aigc_short_drama_canvas')->where(['id'=>$canvas,'tenant_id'=>91001,'user_id'=>92001])->delete();
    }
}
echo "NOT_RUN production recovery scheduler and provider reconciliation query; exact synthetic process-kill boundaries only\n";
