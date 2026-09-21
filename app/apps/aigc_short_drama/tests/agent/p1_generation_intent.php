<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use think\facade\Db;
use app\common\service\app\aigc_short_drama\ShortDramaCanvasService as Canvas;
use app\common\service\app\aigc_short_drama\canvas_agent\GenerationIntentService as Intent;
use app\common\service\point\PointService;
function intentReject(callable $call,string $expected): void {
    try {$call();} catch (RuntimeException $error) {agentCheck($error->getMessage()===$expected,$expected);return;}
    throw new RuntimeException('Expected '.$expected);
}
Db::startTrans();
try {
    Db::name('tenant')->insert(['id'=>91001,'sn'=>'intent-fixture','create_time'=>time(),'point_balance'=>100]);
    Db::name('user')->insert(['id'=>92001,'sn'=>92001,'account'=>'intent-fixture','tenant_id'=>91001,'user_money'=>100]);
    $id=Canvas::create(91001,92001,['title'=>'P1 generation intent fixture'])['id'];
    Canvas::save(91001,92001,['id'=>$id,'nodes'=>[['id'=>1,'type'=>'text','metadata'=>['content_revision'=>3]]]]);
    $input=['prompt'=>'Synthetic only','model_code'=>'mock','skill_snapshot'=>['version'=>7,'content'=>'frozen skill']];
    $intent=Intent::reserve(91001,92001,$id,'first','1','text',$input);
    $snapshot=json_decode($intent['snapshot_json'],true);
    agentCheck($snapshot['input']===$input && $snapshot['content_revision']===3,'intent freezes graph target revision and input snapshot');
    $bound=Canvas::current(91001,92001,$id);
    agentCheck((int)$bound['nodes'][0]['metadata']['active_generation_id']===(int)$intent['canvas_run_id'],'reservation atomically binds authoritative active generation');
    $received=0;
    for ($i=0;$i<10;$i++) {
        $duplicate=Intent::reserve(91001,92001,$id,'first','1','text',$input);
        agentCheck($duplicate['canvas_run_id']===$intent['canvas_run_id'],'duplicate submission keeps same logical run '.$i);
        $claim=Intent::claim(91001,92001,(int)$duplicate['id']);
        if (!$claim) continue;
        $received++;
        PointService::consumeBusinessAmountsInCurrentTransaction(91001,92001,1,2,'intent-'.$intent['id'],'Intent simulated downstream');
        Intent::accepted(91001,92001,(int)$claim['id'],$claim['claim_token'],(int)$claim['fencing_version'],'mock-receipt',['content'=>'Synthetic response'],true);
    }
    agentCheck($received===1,'ten duplicate submissions invoke synthetic downstream once');
    agentCheck(Db::name('tenant_point_log')->where('tenant_id',91001)->count()===1 && Db::name('user_account_log')->where('user_id',92001)->count()===1,'authoritative point ledgers each charge once');
    agentCheck((float)Db::name('tenant')->where('id',91001)->value('point_balance')===99.0 && (float)Db::name('user')->where('id',92001)->value('user_money')===98.0,'duplicate requests preserve expected tenant/user balances');
    intentReject(fn()=>Intent::reserve(91001,92001,$id,'first','1','text',['prompt'=>'changed']),'IDEMPOTENCY_CONFLICT');
    intentReject(fn()=>Intent::claim(91002,92001,(int)$intent['id']),'GENERATION_INTENT_NOT_FOUND');
    intentReject(fn()=>Intent::claim(91001,92002,(int)$intent['id']),'GENERATION_INTENT_NOT_FOUND');
    $lost=Intent::reserve(91001,92001,$id,'lost-response','1','text',$input);
    $claim=Intent::claim(91001,92001,(int)$lost['id']);
    $received++; // Simulate provider acceptance followed by connection loss.
    Intent::unknown(91001,92001,(int)$claim['id'],$claim['claim_token'],(int)$claim['fencing_version']);
    $retry=Intent::reserve(91001,92001,$id,'lost-response','1','text',$input);
    agentCheck($retry['state']==='needs_reconciliation' && Intent::claim(91001,92001,(int)$retry['id'])===null && $received===2,'unknown accepted outcome is never resubmitted');
    Intent::accepted(91001,92001,(int)$claim['id'],$claim['claim_token'],(int)$claim['fencing_version'],'late',['content'=>'late response'],true);
    $late=Db::name('aigc_short_drama_canvas_run')->where('id',$claim['canvas_run_id'])->find();
    agentCheck($late['status']==='needs_reconciliation' && $late['provider_task_id']==='late' && json_decode($late['result_json'],true)['content']==='late response','late original receipt is retained without claiming success');
    Intent::accepted(91001,92001,(int)$claim['id'],$claim['claim_token'],(int)$claim['fencing_version'],'late',['content'=>'late response'],true);
    agentCheck(Intent::claim(91001,92001,(int)$claim['id'])===null,'duplicate late receipt never resubmits');
    intentReject(fn()=>Intent::accepted(91001,92001,(int)$claim['id'],'wrong-token',(int)$claim['fencing_version'],'forged',[],true),'STALE_SUBMISSION_CLAIM');
    intentReject(fn()=>Intent::accepted(91001,92001,(int)$claim['id'],$claim['claim_token'],(int)$claim['fencing_version']+1,'forged',[],true),'STALE_SUBMISSION_CLAIM');
    intentReject(fn()=>Intent::accepted(91001,92001,(int)$claim['id'],$claim['claim_token'],(int)$claim['fencing_version'],'other',[],true),'PROVIDER_RECEIPT_CONFLICT');
    Intent::unknown(91001,92001,(int)$claim['id'],$claim['claim_token'],(int)$claim['fencing_version']);
    agentCheck(Db::name(Intent::TABLE)->where('id',$claim['id'])->value('error_code')==='LATE_ACCEPTANCE_RECORDED','later unknown observation cannot erase receipt evidence');
    $expired=Intent::reserve(91001,92001,$id,'expired','1','text',$input);
    $claim=Intent::claim(91001,92001,(int)$expired['id']);
    Db::name(Intent::TABLE)->where('id',$claim['id'])->update(['lease_until'=>time()-1]);
    agentCheck(Intent::expire(91001,92001,(int)$claim['id']) && Intent::claim(91001,92001,(int)$claim['id'])===null,'expired submit enters reconciliation instead of being requeued');
    Intent::accepted(91001,92001,(int)$claim['id'],$claim['claim_token'],(int)$claim['fencing_version'],'stale',[],true);
    agentCheck(Db::name(Intent::TABLE)->where('id',$claim['id'])->value('provider_task_id')==='stale','expired worker retains task reference without advancing state');
    $slow=Intent::reserve(91001,92001,$id,'slow','1','text',$input);
    $claim=Intent::claim(91001,92001,(int)$slow['id']);
    Db::name(Intent::TABLE)->where('id',$claim['id'])->update(['lease_until'=>time()-1]);
    Intent::accepted(91001,92001,(int)$claim['id'],$claim['claim_token'],(int)$claim['fencing_version'],'slow-receipt',[],true);
    agentCheck(Db::name(Intent::TABLE)->where('id',$claim['id'])->value('state')==='needs_reconciliation','late receipt before expiry scanner also remains unresolved');
    $timeout=Intent::reserve(91001,92001,$id,'timeout','1','text',$input);
    $claim=Intent::claim(91001,92001,(int)$timeout['id']);
    Db::name(Intent::TABLE)->where('id',$claim['id'])->update(['lease_until'=>time()-1]);
    Intent::unknown(91001,92001,(int)$claim['id'],$claim['claim_token'],(int)$claim['fencing_version']);
    agentCheck(Db::name(Intent::TABLE)->where('id',$claim['id'])->value('state')==='needs_reconciliation','expired transport error records unknown outcome instead of losing recovery state');
    $videoCanvas=Canvas::create(91001,92001,['title'=>'Unresolved video receipt'])['id'];
    Canvas::save(91001,92001,['id'=>$videoCanvas,'nodes'=>[['id'=>1,'type'=>'video','metadata'=>[]]]]);
    $video=Intent::reserve(91001,92001,$videoCanvas,'late-video','1','video',[]);
    $claim=Intent::claim(91001,92001,(int)$video['id']);
    $videoTask=Db::name('aigc_video_task')->insertGetId(['tenant_id'=>91001,'user_id'=>92001,'status'=>'success']);
    Db::name(Intent::TABLE)->where('id',$claim['id'])->update(['lease_until'=>time()-1]);
    Intent::accepted(91001,92001,(int)$claim['id'],$claim['claim_token'],(int)$claim['fencing_version'],(string)$videoTask,[],false);
    agentCheck(Canvas::runDetail(91001,92001,(int)$claim['canvas_run_id'])['status']==='needs_reconciliation','ordinary media polling cannot resolve a fenced late receipt');
    $moved=Intent::reserve(91001,92001,$id,'movement','1','text',$input);
    $nodes=Canvas::current(91001,92001,$id)['nodes'];$nodes[0]['x']=999;
    Canvas::save(91001,92001,['id'=>$id,'nodes'=>$nodes]);
    agentCheck(Intent::claim(91001,92001,(int)$moved['id'])!==null,'moving node does not invalidate frozen generation input');
    $older=Intent::reserve(91001,92001,$id,'prepared-older','1','text',$input);
    $newer=Intent::reserve(91001,92001,$id,'prepared-newer','1','text',$input);
    agentCheck(Intent::claim(91001,92001,(int)$older['id'])===null && Db::name(Intent::TABLE)->where('id',$older['id'])->value('error_code')==='GENERATION_SUPERSEDED_BEFORE_SUBMIT','superseded prepared generation is canceled before any provider call');
    agentCheck(Intent::claim(91001,92001,(int)$newer['id'])!==null,'latest prepared generation remains eligible to submit');
    $edited=Intent::reserve(91001,92001,$id,'edited','1','text',$input);
    $nodes=Canvas::current(91001,92001,$id)['nodes'];
    $nodes[0]['metadata']['prompt']='User changed prompt';
    Canvas::save(91001,92001,['id'=>$id,'nodes'=>$nodes]);
    agentCheck(Intent::claim(91001,92001,(int)$edited['id'])===null && Db::name(Intent::TABLE)->where('id',$edited['id'])->value('error_code')==='INPUT_CHANGED_BEFORE_SUBMIT','content change before claim cancels stale generation without submission');
    $deleted=Intent::reserve(91001,92001,$id,'deleted','1','text',$input);
    Canvas::save(91001,92001,['id'=>$id,'nodes'=>[],'removed_node_ids'=>['1']]);
    agentCheck(Intent::claim(91001,92001,(int)$deleted['id'])===null && Db::name(Intent::TABLE)->where('id',$deleted['id'])->value('state')==='canceled','node deleted before claim never reaches downstream');
} finally {Db::rollback();}
echo "NOT_RUN Canvas submit adapter, HTTP retries, real providers, process-kill recovery and reconciliation query; intent foundation only\n";
