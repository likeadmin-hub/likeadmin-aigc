<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';

use app\common\service\app\aigc_short_drama\ShortDramaCanvasService as Canvas;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationActionPlan as ActionPlan;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationExecution as Execution;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationProviderInterface;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationStore as Store;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationWorker as Worker;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationWorkflow as Workflow;
use app\common\service\app\aigc_short_drama\canvas_agent\GraphService;
use think\facade\Db;

final class WorkflowAcceptanceProvider implements ConversationProviderInterface
{
    public function preflight(int $tenant,int $user,array $request): void {}
    public function generate(int $tenant,int $user,array $request): array
    {
        return ['content'=>'这是本阶段的本地验收回复。','tool_calls'=>[],'safety_checked'=>true];
    }
}

$tenant=91051;$user=92051;$config=0;$canvas=0;
if (Db::name('aigc_short_drama_config')->where('tenant_id',$tenant)->count()) throw new RuntimeException('Existing fixture config');
try {
    $config=Db::name('aigc_short_drama_config')->insertGetId(['tenant_id'=>$tenant,'config_json'=>json_encode(['canvas_agent'=>['enabled'=>true,'workflow'=>['enabled'=>true,'enabled_workflows'=>['short_drama_creation']]]],JSON_UNESCAPED_UNICODE),'status'=>1,'create_time'=>time(),'update_time'=>time()]);
    $canvas=Canvas::create($tenant,$user,['title'=>'P5 workflow fixture'])['id'];
    $thread=Store::create($tenant,$user,$canvas,'workflow-thread')['id'];
    $ack=Store::enqueue($tenant,$user,$canvas,$thread,['request_key'=>'workflow-route','content'=>'我想创作一部悬疑短剧','base_revision'=>0],static function (array $conversation) use ($tenant): array {
        $prepared=Workflow::prepare($tenant,$conversation,'我想创作一部悬疑短剧',[],[],['generation_mode'=>'auto','reasoning_model'=>'text-a']);
        return ['settings'=>['generation_mode'=>'auto','reasoning_model'=>['id'=>'text-a']],'skill'=>[],'workflow'=>$prepared['workflow'],'thread_settings'=>$prepared['thread_settings']];
    });
    $snapshot=json_decode((string)Db::name(Store::PREFIX.'run')->where('id',$ack['run_id'])->value('context_snapshot'),true);
    agentCheck(($snapshot['workflow']['workflow_snapshot']['key']??'')==='short_drama_creation','semantic route freezes the platform workflow key in the run');
    agentCheck(($snapshot['workflow']['workflow_snapshot']['version']??'')===Workflow::VERSION,'workflow version is immutable in the accepted run');
    agentCheck(($snapshot['workflow']['stage_state']['key']??'')==='intake','short drama route starts in collection without a canvas node');
    agentCheck(Execution::stop($tenant,$user,$canvas,$thread,$ack['run_id'])['status']==='canceled','workflow card actions wait for the active conversational run to finish');
    $view=Workflow::read($tenant,$user,$canvas,$thread);
    agentCheck(($view['card']['slot']['key']??'')==='genre','first card is the server-owned story-type slot');
    $answers=['genre'=>'悬疑反转','episode_count'=>'10集（微短剧）','episode_duration'=>'1分钟','visual_style'=>'电影写实','audience'=>'年轻女性','characters'=>'记者与失踪的姐姐','ending'=>'反转开放'];
    foreach ($answers as $slot=>$value) {
        $view=Workflow::read($tenant,$user,$canvas,$thread);
        agentCheck(($view['card']['slot']['key']??'')===$slot,'collection preserves the configured slot order: '.$slot);
        $result=Workflow::answer($tenant,$user,$canvas,$thread,(int)$view['workflow']['state_revision'],$slot,$value);
        agentCheck(isset($result['workflow']['slot_values'][$slot]),'answer is persisted for '.$slot);
    }
    $completed=Workflow::read($tenant,$user,$canvas,$thread);
    agentCheck(($completed['workflow']['stage_state']['key']??'')==='script','complete intake advances to the script conversation stage');
    agentCheck(($completed['card']['type']??'')==='stage','post-intake projection is a factual stage card');
    $provider=new WorkflowAcceptanceProvider();
    $runStage=static function (string $requestKey) use ($tenant,$user,$canvas,$thread,$provider): array {
        $ack=Store::enqueue($tenant,$user,$canvas,$thread,['request_key'=>$requestKey,'content'=>'继续当前创作阶段','base_revision'=>0],static function (array $conversation) use ($tenant): array {
            $prepared=Workflow::prepare($tenant,$conversation,'继续当前创作阶段',[],[],['generation_mode'=>'auto','reasoning_model'=>['id'=>'fixture-model']]);
            return ['settings'=>['generation_mode'=>'auto','reasoning_model'=>['id'=>'fixture-model']],'skill'=>[],'workflow'=>$prepared['workflow'],'thread_settings'=>$prepared['thread_settings']];
        });
        agentCheck(Worker::process($tenant,$user,(int)$ack['run_id'],$provider)==='success','local Worker completes workflow stage '.$requestKey);
        return Workflow::read($tenant,$user,$canvas,$thread);
    };
    $afterScript=$runStage('workflow-script-stage');
    agentCheck(($afterScript['workflow']['stage_state']['key']??'')==='art' && ($afterScript['workflow']['stage_state']['status']??'')==='ready','script reply advances to art planning');
    $afterArt=$runStage('workflow-art-stage');
    agentCheck(($afterArt['workflow']['stage_state']['key']??'')==='assets' && ($afterArt['workflow']['stage_state']['status']??'')==='awaiting_plan_confirmation','art reply stops auto flow at the required image-plan confirmation');
    agentCheck(preg_match('/^[a-f0-9]{64}$/D',(string)($afterArt['workflow']['plan_hash']??''))===1,'art plan receives a frozen integrity hash');
    try {
        Workflow::prepare($tenant,Db::name(Store::PREFIX.'thread')->where('id',$thread)->find(),'attempt to bypass plan confirmation',[],[],['generation_mode'=>'auto']);
        throw new RuntimeException('workflow message bypassed plan confirmation');
    } catch (RuntimeException $error) { agentCheck($error->getMessage()==='WORKFLOW_PLAN_CONFIRMATION_REQUIRED','unconfirmed auto plan blocks the next Agent request'); }
    $confirmed=Workflow::confirmPlan($tenant,$user,$canvas,$thread,(int)$afterArt['workflow']['state_revision'],(string)$afterArt['workflow']['plan_hash']);
    agentCheck(($confirmed['workflow']['plan_confirmation']['status']??'')==='confirmed' && Workflow::mayAutoSubmit($confirmed['workflow']),'confirmed plan is the only workflow state eligible for image auto-submit');
    $videoPlan=ActionPlan::parse('分镜视频节点已规划。<canvas-actions>{"nodes":[{"type":"video","title":"分镜 01","prompt":"角色推门进入办公室","key":"shot_1"},{"type":"video","title":"分镜 02","prompt":"角色回头看向窗外","key":"shot_2","depends_on":["shot_1"]}]}</canvas-actions>','video_nodes');
    agentCheck(count($videoPlan['nodes'])===2 && $videoPlan['nodes'][1]['type']==='video','video stage accepts one bounded batch of storyboard video nodes');
    $videoWorkflow=$confirmed['workflow'];$videoWorkflow['stage_state']=['key'=>'video_nodes','status'=>'running'];
    $videoEffects=Db::transaction(static function () use ($canvas,$videoPlan,$videoWorkflow): array {
        $document=Db::name(GraphService::TABLE)->where('id',$canvas)->lock(true)->find();
        return GraphService::appendAgentNodesLocked($document,$videoPlan['nodes'],[],true,[],1,$videoWorkflow);
    });
    agentCheck(!array_filter($videoEffects['nodes'],static fn(array $node): bool => $node['auto_submit']),'storyboard videos are inserted but never auto-submitted');
    $audioPlan=ActionPlan::parse('音频规划已插入。<canvas-actions>{"nodes":[{"type":"audio","title":"音频规划（暂未开放）","prompt":"片头音乐与角色对白节奏规划","key":"audio_plan"}]}</canvas-actions>','audio_plan');
    $audioWorkflow=$confirmed['workflow'];$audioWorkflow['stage_state']=['key'=>'audio_plan','status'=>'running'];
    $audioEffects=Db::transaction(static function () use ($canvas,$audioPlan,$audioWorkflow): array {
        $document=Db::name(GraphService::TABLE)->where('id',$canvas)->lock(true)->find();
        return GraphService::appendAgentNodesLocked($document,$audioPlan['nodes'],[],false,[],1,$audioWorkflow);
    });
    $audioNodeId=(string)$audioEffects['nodes'][0]['id'];
    $persistedNodes=json_decode((string)Db::name(GraphService::TABLE)->where('id',$canvas)->value('nodes_json'),true);
    $audioNode=array_values(array_filter($persistedNodes,static fn(array $node): bool => (string)$node['id']===$audioNodeId))[0]??[];
    agentCheck(!empty($audioNode['metadata']['workflow_audio_disabled']) && ($audioNode['metadata']['workflow_submission_policy']??'')==='disabled','audio planning node is server-marked as not generatable');
    try { Canvas::submitIdempotent($tenant,$user,['canvas_id'=>$canvas,'node_id'=>$audioNodeId,'type'=>'audio']); throw new RuntimeException('disabled audio task reached provider boundary'); }
    catch (Throwable $error) { agentCheck($error->getMessage()==='WORKFLOW_AUDIO_GENERATION_UNAVAILABLE','audio planning node cannot enter Provider or billing submission'); }
    try { Workflow::read($tenant+1,$user,$canvas,$thread); throw new RuntimeException('cross tenant workflow read passed'); }
    catch (RuntimeException $error) { agentCheck($error->getMessage()==='CANVAS_NOT_FOUND','workflow state cannot be read across tenants'); }
    $plain=Store::create($tenant,$user,$canvas,'plain-thread')['id'];
    $prepared=Workflow::prepare($tenant,['settings_json'=>'{}'],'/other-skill 请写一段文案',[],[],[]);
    agentCheck($prepared['workflow']===[],'unrelated explicit slash Skill does not enter the short-drama workflow');
} finally {
    if ($canvas) {
        foreach (['outbox','event','message','run','thread'] as $kind) Db::name(Store::PREFIX.$kind)->where(['canvas_id'=>$canvas,'tenant_id'=>$tenant,'user_id'=>$user])->delete();
        Db::name('aigc_short_drama_canvas')->where(['id'=>$canvas,'tenant_id'=>$tenant,'user_id'=>$user])->delete();
    }
    if ($config) Db::name('aigc_short_drama_config')->where(['id'=>$config,'tenant_id'=>$tenant])->delete();
}
echo "NOT_RUN real Provider, image plan quotation and paid media submission\n";
