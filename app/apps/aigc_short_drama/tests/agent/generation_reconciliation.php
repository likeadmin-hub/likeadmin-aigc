<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use think\facade\Db;
use app\common\service\app\aigc_short_drama\ShortDramaCanvasService as Canvas;
use app\common\service\app\aigc_short_drama\canvas_agent\GenerationIntentService as Intent;
function recFixture(string $type,string $status,string $billing,bool $receipt=true): array {
    $canvas=(int)Canvas::create(91001,92001,['title'=>'Local reconciliation fixture'])['id'];
    Canvas::save(91001,92001,['id'=>$canvas,'nodes'=>[['id'=>1,'type'=>$type,'metadata'=>[]]]]);
    $intent=Intent::reserve(91001,92001,$canvas,'original','1',$type,[]);
    $claim=Intent::claim(91001,92001,(int)$intent['id']);
    $prefix=['image'=>'aigc_image','video'=>'aigc_video','audio'=>'aigc_music'][$type];
    $task=(int)Db::name($prefix.'_task')->insertGetId(['tenant_id'=>91001,'user_id'=>92001,'status'=>$status,'create_time'=>time(),'delete_time'=>0]);
    $app=(int)Db::name('ai_app_task')->insertGetId(['task_no'=>'canvas-rec-'.$intent['id'],'tenant_id'=>91001,'user_id'=>92001,'app_code'=>$prefix,'business_table'=>$prefix.'_task','business_id'=>$task,'status'=>$status,'idempotency_key'=>'canvas-rec-'.$intent['id'],'create_time'=>time()]);
    Db::name($prefix.'_task')->where('id',$task)->update(['app_task_id'=>$app]);
    $usage=(int)Db::name('ai_consumption_log')->insertGetId(['consume_no'=>'canvas-rec-'.$intent['id'],'app_task_id'=>$app,'tenant_id'=>91001,'user_id'=>92001,'app_code'=>$prefix,'run_status'=>$status,'billing_status'=>$billing,'upstream_task_id'=>$receipt?'fixture-receipt':'','create_time'=>time()]);
    Intent::bindLocalTask(91001,92001,(int)$claim['id'],$claim['claim_token'],(int)$claim['fencing_version'],$task);
    Intent::unknown(91001,92001,(int)$claim['id'],$claim['claim_token'],(int)$claim['fencing_version']);
    return compact('canvas','claim','prefix','task','app','usage');
}
Db::startTrans();
try {
    foreach (['image','video','audio'] as $type) {
        $f=recFixture($type,'running','reserved',false);$run=(int)$f['claim']['canvas_run_id'];
        agentCheck(!Intent::reconcile(91001,92001,$run),'no receipt never implies safe acceptance '.$type);
        try { Intent::reserve(91001,92001,$f['canvas'],'new-key','1',$type,['prompt'=>'changed']); throw new Exception('must reject'); }
        catch (RuntimeException $e) { agentCheck(str_contains($e->getMessage(),'先核对'),'new key cannot bypass unresolved node '.$type); }
        $replay=Intent::reserve(91001,92001,$f['canvas'],'original','1',$type,[]);
        agentCheck((int)$replay['canvas_run_id']===$run,'same key remains idempotent '.$type);
        Db::name('ai_consumption_log')->where('id',$f['usage'])->update(['upstream_task_id'=>'durable-receipt']);
        agentCheck(Intent::reconcile(91001,92001,$run),'durable receipt restores running '.$type);
        Intent::accepted(91001,92001,(int)$f['claim']['id'],$f['claim']['claim_token'],(int)$f['claim']['fencing_version'],(string)$f['task'],[],false);
        agentCheck(Intent::reconcile(91001,92001,$run),'reconciliation and late worker are idempotent '.$type);
        $f=recFixture($type,'failed','reserved');$run=(int)$f['claim']['canvas_run_id'];
        agentCheck(!Intent::reconcile(91001,92001,$run),'failure without refund remains uncertain '.$type);
        Db::name('ai_consumption_log')->where('id',$f['usage'])->update(['billing_status'=>'refunded']);
        $before=Db::name('ai_consumption_log')->where('id',$f['usage'])->find();
        agentCheck(Intent::reconcile(91001,92001,$run),'terminal refunded failure reconciles '.$type);
        agentCheck(Db::name('aigc_short_drama_canvas_run')->where('id',$run)->value('status')==='failed','failure displayed '.$type);
        agentCheck(Db::name('ai_consumption_log')->where('id',$f['usage'])->find()===$before,'no second refund or billing mutation '.$type);
        $f=recFixture($type,'success','settled');$run=(int)$f['claim']['canvas_run_id'];
        agentCheck(!Intent::reconcile(91001,92001,$run),'success waits for durable results '.$type);
        Db::name($f['prefix'].'_result')->insert(['tenant_id'=>91001,'user_id'=>92001,'task_id'=>$f['task'],'delete_time'=>0,($type==='audio'?'audio':$type).'_uri'=>'uploads/fixture.png']);
        Db::name('ai_app_task')->where('id',$f['app'])->update(['user_id'=>92002]);
        agentCheck(!Intent::reconcile(91001,92001,$run),'cross-user evidence rejected '.$type);
        Db::name('ai_app_task')->where('id',$f['app'])->update(['user_id'=>92001,'business_id'=>0]);
        agentCheck(!Intent::reconcile(91001,92001,$run),'unrelated business evidence rejected '.$type);
        Db::name('ai_app_task')->where('id',$f['app'])->update(['business_id'=>$f['task']]);
        agentCheck(Intent::reconcile(91001,92001,$run),'settled completed result restores projection path '.$type);
        $detail=Canvas::runDetail(91001,92001,$run);
        agentCheck($detail['status']==='success' && !empty($detail['results'][0]['url']),'detail read restores completed media '.$type);
        $node=Canvas::current(91001,92001,$f['canvas'])['nodes'][0];
        agentCheck(($node['metadata']['status']??'')==='success' && !empty($node['metadata']['url']),'recovered result projects into the saved node '.$type);
    }
    $f=recFixture('video','running','reserved');$id=(int)$f['claim']['id'];
    Db::name(Intent::TABLE)->where('id',$id)->update(['state'=>'submitting','lease_until'=>time()+120]);
    agentCheck(!Intent::reconcile(91001,92001,(int)$f['claim']['canvas_run_id']),'active submit lease is never stolen');
    Db::name(Intent::TABLE)->where('id',$id)->update(['lease_until'=>time()-1]);
    agentCheck(Intent::reconcile(91001,92001,(int)$f['claim']['canvas_run_id']),'expired lease recovers only with existing receipt');
} finally { Db::rollback(); }
echo "No provider submission, billing mutation, or persistent fixtures.\n";
