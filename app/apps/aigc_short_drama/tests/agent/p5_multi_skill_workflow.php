<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';

use app\common\service\app\aigc_short_drama\ShortDramaCanvasService as Canvas;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationActionPlan as ActionPlan;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationExecution as Execution;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationIntentRouter as IntentRouter;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationTextContext as TextContext;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationWorkflowTurn as WorkflowTurn;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationIntakeDraft as IntakeDraft;
use app\common\service\app\aigc_short_drama\canvas_agent\FeatureGate;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationProviderInterface;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationStore as Store;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationWorker as Worker;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationWorkflow as Workflow;
use app\common\service\app\aigc_short_drama\canvas_agent\GraphService;
use think\facade\Db;

final class WorkflowAcceptanceProvider implements ConversationProviderInterface
{
    public string $content='这是本阶段的本地验收回复。';
    public array $lastRequest=[];
    public function preflight(int $tenant,int $user,array $request): void {}
    public function generate(int $tenant,int $user,array $request): array
    {
        $this->lastRequest=$request;
        return ['content'=>$this->content,'tool_calls'=>[],'safety_checked'=>true];
    }
}

/** Local price fixture only: it proves the plan calls the shared estimate
 * boundary without reserving points, creating a task or reaching a Provider. */
final class WorkflowImageQuoteFixture
{
    public static int $calls=0;
    public static function estimate(int $tenant,array $params): array
    {
        self::$calls++;
        return ['market_product_id'=>77,'market_sku_id'=>88,'tenant_cost_points'=>3,'user_charge_points'=>5,'usage_unit'=>'call','settlement_mode'=>'reserved'];
    }
}
class_alias(WorkflowImageQuoteFixture::class,'app\\common\\service\\app\\aigc_image\\AigcImageService');

$tenant=91051;$user=92051;$config=0;$canvas=0;$stageSkill=0;
if (Db::name('aigc_short_drama_config')->where('tenant_id',$tenant)->count()) throw new RuntimeException('Existing fixture config');
try {
    $stageSkill=Db::name('aigc_short_drama_skill')->insertGetId(['tenant_id'=>$tenant,'skill_key'=>'workflow_script_fixture','name'=>'剧本创作验收 Skill','status'=>1,'release_status'=>'active','version'=>1,'published_version'=>1]);
    Db::name('aigc_short_drama_skill_version')->insert(['tenant_id'=>$tenant,'skill_id'=>$stageSkill,'version'=>1,'release_status'=>'active','snapshot_json'=>json_encode(['name'=>'剧本创作验收 Skill','skill_key'=>'workflow_script_fixture','definition'=>['instructions'=>'先确认故事的冲突与角色动机。'],'model_policy'=>[],'execution_policy'=>[]],JSON_UNESCAPED_UNICODE)]);
    $config=Db::name('aigc_short_drama_config')->insertGetId(['tenant_id'=>$tenant,'config_json'=>json_encode(['canvas_agent'=>['enabled'=>true,'workflow'=>['enabled'=>true,'enabled_workflows'=>['short_drama_creation'],'stage_skills'=>['script'=>[['skill_id'=>$stageSkill,'skill_version'=>1]]]]]],JSON_UNESCAPED_UNICODE),'status'=>1,'create_time'=>time(),'update_time'=>time()]);
    $defaultSkills=FeatureGate::workflowStageSkillSelections($tenant);
    agentCheck(count(Workflow::defaultSkillKeys())===8 && count($defaultSkills['intake']??[])===1 && count($defaultSkills['assets']??[])===2 && count($defaultSkills['storyboard']??[])===4,'platform workflow provides a complete executable multi-Skill baseline for every stage');
    agentCheck(($defaultSkills['script'][0]['skill_id']??0)===$stageSkill,'a tenant-persisted stage Skill overrides only that stage while untouched stages retain platform defaults');
    $originalConfig=(string)Db::name('aigc_short_drama_config')->where('id',$config)->value('config_json');
    $staleConfig=json_decode($originalConfig,true,512,JSON_THROW_ON_ERROR);
    $artBuiltin=$defaultSkills['art'][0];
    $staleConfig['canvas_agent']['workflow']['stage_skills']['art']=[['skill_id'=>$artBuiltin['skill_id'],'skill_version'=>$artBuiltin['skill_version']-1]];
    Db::name('aigc_short_drama_config')->where('id',$config)->update(['config_json'=>json_encode($staleConfig,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
    $effective=FeatureGate::workflowStageSkillSelections($tenant);
    agentCheck(($effective['art'][0]['skill_version']??0)===$artBuiltin['skill_version']
        && ($effective['script'][0]['skill_id']??0)===$stageSkill,'stale platform Skill selections resolve to the current published version without replacing tenant-owned stage Skills');
    Db::name('aigc_short_drama_config')->where('id',$config)->update(['config_json'=>$originalConfig]);
    $canvas=Canvas::create($tenant,$user,['title'=>'P5 workflow fixture'])['id'];
    $thread=Store::create($tenant,$user,$canvas,'workflow-thread')['id'];
    $ack=Store::enqueue($tenant,$user,$canvas,$thread,['request_key'=>'workflow-route','content'=>'/short-drama 我想创作一部悬疑短剧','base_revision'=>0],static function (array $conversation) use ($tenant): array {
        $preferences=['generation_mode'=>'auto','reasoning_model'=>['id'=>'text-a'],'image_model'=>['id'=>'fixture-image','model_code'=>'fixture-image']];
        $prepared=Workflow::prepare($tenant,$conversation,'/short-drama 我想创作一部悬疑短剧',[],[],$preferences);
        return ['settings'=>$preferences,'skill'=>[],'workflow'=>$prepared['workflow'],'thread_settings'=>$prepared['thread_settings']];
    });
    $snapshot=json_decode((string)Db::name(Store::PREFIX.'run')->where('id',$ack['run_id'])->value('context_snapshot'),true);
    agentCheck(($snapshot['workflow']['workflow_snapshot']['key']??'')==='short_drama_creation','explicit route freezes the platform workflow key in the run');
    agentCheck(($snapshot['workflow']['workflow_snapshot']['version']??'')===Workflow::VERSION,'workflow version is immutable in the accepted run');
    agentCheck(($snapshot['workflow']['workflow_snapshot']['stage_skill_versions']['script'][0]['skill_key']??'')==='workflow_script_fixture' && ($snapshot['workflow']['workflow_snapshot']['stage_skill_versions']['script'][0]['version']??0)===1,'workflow freezes the tenant-authorized published Skill version for its configured stage');
    agentCheck(($snapshot['workflow']['stage_state']['key']??'')==='intake','short drama route starts in collection without a canvas node');
    agentCheck(($snapshot['workflow']['creative_brief']??'')==='/short-drama 我想创作一部悬疑短剧','manual route preserves the initiating story brief across later compact stage handoffs');
    $legacyBrief=TextContext::creativeBrief([], [
        ['role'=>'user','content'=>'请制作《耳机的秘密》，围绕耳机店的降噪误会。'],
        ['role'=>'assistant','content'=>'已进入流程。'],
        ['role'=>'user','content'=>'请重做《耳机的秘密》，不要咖啡店剧情。'],
        ['role'=>'user','content'=>'请基于已确认的信息生成剧本。'],
    ]);
    agentCheck(str_contains($legacyBrief,'耳机店的降噪误会') && !str_contains($legacyBrief,'咖啡店'),'legacy workflow recovers the initiating titled brief rather than a later stage revision or automatic instruction');
    $anchored=$snapshot['workflow'];
    $anchored['stage_state']['key']='script';
    $anchored['creative_brief']=$legacyBrief;
    Workflow::assertStoryAnchor($anchored,[['artifact'=>'story_setting','prompt'=>'项目标题：耳机的秘密；小禾在耳机店化解误会。']]);
    try { Workflow::assertStoryAnchor($anchored,[['artifact'=>'story_setting','prompt'=>'项目标题：误会一杯咖啡；小禾在咖啡店。']]); throw new RuntimeException('unrelated screenplay accepted'); }
    catch (RuntimeException $error) { agentCheck($error->getMessage()==='INVALID_AGENT_ACTION','a different project title is rejected before stage publication'); }
    agentCheck(Execution::stop($tenant,$user,$canvas,$thread,$ack['run_id'])['status']==='canceled','workflow card actions wait for the active conversational run to finish');
    // Preserve coverage for a conversation frozen on the previous catalog:
    // its existing three-text-node contract must not silently change mid-run.
    $legacyRow=Db::name(Store::PREFIX.'thread')->where('id',$thread)->find();
    $legacySettings=json_decode((string)$legacyRow['settings_json'],true,512,JSON_THROW_ON_ERROR);
    $legacySettings['workflow_state']['workflow_snapshot']['version']='2026-09-23.2';
    Db::name(Store::PREFIX.'thread')->where('id',$thread)->update(['settings_json'=>json_encode($legacySettings,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);
    $view=Workflow::read($tenant,$user,$canvas,$thread);
    agentCheck(($view['card']['slot']['key']??'')==='genre','first card is the server-owned story-type slot');
    agentCheck(($view['card']['stage_label']??'')==='创作采集' && ($view['card']['skills']??[])===['创作采集'],'workflow card projects the frozen stage and its actual platform Skill list');
    $answers=['genre'=>'悬疑反转','episode_count'=>'10集（微短剧）','episode_duration'=>'1分钟','visual_style'=>'电影写实','aspect_ratio'=>'16:9','audience'=>'年轻女性','characters'=>'记者与失踪的姐姐','ending'=>'反转开放'];
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
    $runStage=static function (string $requestKey,string $reply='这是本阶段的本地验收回复。') use ($tenant,$user,$canvas,$thread,$provider): array {
        $provider->content=$reply;
        $revision=(int)Db::name(GraphService::TABLE)->where('id',$canvas)->value('graph_revision');
        $ack=Store::enqueue($tenant,$user,$canvas,$thread,['request_key'=>$requestKey,'content'=>'继续当前创作阶段','base_revision'=>$revision],static function (array $conversation) use ($tenant): array {
            $preferences=['generation_mode'=>'auto','reasoning_model'=>['id'=>'fixture-model'],'image_model'=>['id'=>'fixture-image','model_code'=>'fixture-image']];
            $prepared=Workflow::prepare($tenant,$conversation,'继续当前创作阶段',[],[],$preferences);
            return ['settings'=>$preferences,'skill'=>[],'workflow'=>$prepared['workflow'],'thread_settings'=>$prepared['thread_settings']];
        });
        agentCheck(Worker::process($tenant,$user,(int)$ack['run_id'],$provider)==='success','local Worker completes workflow stage '.$requestKey);
        return Workflow::read($tenant,$user,$canvas,$thread);
    };
    $scriptReply='剧本与角色设定已完成。<canvas-actions>{"nodes":[{"type":"text","artifact":"story_setting","title":"故事设定","prompt":"project_title: 雨夜回音\nlogline: 记者妹妹循着姐姐留下的录音追查真相。\nworld_setting: 雨夜旧城改造调查。\ncharacter_profiles: 林夏，调查记者；林秋，失踪姐姐。","key":"story"},{"type":"text","artifact":"episode_outline","title":"分集大纲","prompt":"episode_outline: 第一集，林夏收到姐姐录音；结尾收到匿名短信。\nscene_script: 雨夜办公室、旧档案室、河岸停车场。","key":"outline","depends_on":["story"]},{"type":"text","artifact":"storyboard_script","title":"分镜脚本","prompt":"storyboard_script: 镜头01，林夏播放录音；镜头02，手机收到匿名短信。","key":"boards","depends_on":["outline"]}]}</canvas-actions>';
    $afterScript=$runStage('workflow-script-stage',$scriptReply);
    agentCheck(($afterScript['workflow']['stage_state']['key']??'')==='script' && ($afterScript['workflow']['stage_state']['status']??'')==='awaiting_stage_confirmation','script reply remains reviewable until the owner confirms its exact structured text plan');
    $scriptNodes=json_decode((string)Db::name(GraphService::TABLE)->where('id',$canvas)->value('nodes_json'),true);
    agentCheck(count($scriptNodes)===0,'unconfirmed script content never mutates the canvas graph');
    $scriptConfirmed=Workflow::confirmStagePlan($tenant,$user,$canvas,$thread,(int)$afterScript['workflow']['state_revision']);
    agentCheck(($scriptConfirmed['workflow']['stage_state']['key']??'')==='art' && ($scriptConfirmed['workflow']['stage_state']['status']??'')==='ready' && count($scriptConfirmed['canvas_actions']['nodes']??[])===3,'confirmed script plan atomically writes text nodes and advances to art planning');
    $scriptNodes=json_decode((string)Db::name(GraphService::TABLE)->where('id',$canvas)->value('nodes_json'),true);
    agentCheck(count($scriptNodes)===3 && ($scriptNodes[0]['metadata']['workflow_artifact']??'')==='story_setting' && str_contains((string)($scriptNodes[0]['metadata']['content']??''),'project_title'),'confirmed script stage writes durable structured story, episode and storyboard text nodes');
    $scriptEdges=json_decode((string)Db::name(GraphService::TABLE)->where('id',$canvas)->value('edges_json'),true);
    agentCheck(count(array_filter($scriptEdges,static fn(array $edge): bool => ($edge['role']??'')==='agent_dependency'))===2,'structured script artifacts retain their story-to-outline-to-storyboard dependency edges');
    agentCheck(str_contains((string)($provider->lastRequest['messages'][count($provider->lastRequest['messages'])-1]['content']??''),'workflow_stage_skills') && str_contains((string)($provider->lastRequest['messages'][count($provider->lastRequest['messages'])-1]['content']??''),'workflow_script_fixture'),'Worker receives only the frozen configured stage Skill in its structured conversation context');
    agentCheck(str_contains((string)($provider->lastRequest['messages'][0]['content']??''),'workflow_creative_brief') && str_contains((string)($provider->lastRequest['messages'][0]['content']??''),'我想创作一部悬疑短剧'),'script stage keeps the originating story request, not only the eight production slots');
    agentCheck(($provider->lastRequest['response_format']['type']??'')==='json_object','workflow text stages request a provider-native structured JSON object instead of relying on decorative markdown tags');
    agentCheck(($provider->lastRequest['max_tokens']??0)===4096 && ($provider->lastRequest['enable_thinking']??true)===false,'workflow projection limits model output and disables expanded reasoning before a durable stage run');
    $jsonPlan=ActionPlan::parse('{"reply_markdown":"# 真实剧本","canvas_actions":{"nodes":[{"type":"text","artifact":"story_setting","title":"故事设定","prompt":"project_title: 雨夜回音\nlogline: 追查姐姐。","key":"story"},{"type":"text","artifact":"episode_outline","title":"分集大纲","prompt":"episode_outline: 第一集。\nscene_script: 雨夜公寓。","key":"outline","depends_on":["story"]},{"type":"text","artifact":"storyboard_script","title":"分镜脚本","prompt":"storyboard_script: 镜头01。","key":"boards","depends_on":["outline"]}]}}','script');
    agentCheck(($jsonPlan['text']??'')==='# 真实剧本' && count($jsonPlan['nodes']??[])===3,'structured JSON keeps the visible reply separate from server-validated workflow nodes');
    try { ActionPlan::parse('# 只有排版正文','script'); throw new RuntimeException('plain stage reply accepted'); }
    catch (RuntimeException $error) { agentCheck($error->getMessage()==='INVALID_AGENT_ACTION','markdown-only workflow text reply is rejected instead of falsely completing a graph-writing stage'); }
    $enqueueActive=static function (string $key,string $content) use ($tenant,$user,$canvas,$thread): array {
        $revision=(int)Db::name(GraphService::TABLE)->where('id',$canvas)->value('graph_revision');
        return Store::enqueue($tenant,$user,$canvas,$thread,['request_key'=>$key,'content'=>$content,'base_revision'=>$revision],static function (array $conversation) use ($tenant,$content): array {
            $current=Workflow::currentState($conversation);
            $paused=in_array((string)($current['stage_state']['status']??''),['awaiting_plan_confirmation','awaiting_stage_confirmation','reviewing_intake'],true);
            $preferences=['generation_mode'=>'auto','reasoning_model'=>['id'=>'fixture-model'],'image_model'=>['id'=>'fixture-image','model_code'=>'fixture-image']];
            $candidate=$paused ? $current : Workflow::prepare($tenant,$conversation,$content,[],[],$preferences)['workflow'];
            $routing=array_replace(IntentRouter::snapshot($tenant),['version'=>2])+['kind'=>'active_workflow','workflow_candidate'=>$candidate,
                'base_workflow_revision'=>(int)$current['state_revision'],'workflow_paused'=>$paused];
            return ['settings'=>$preferences,'skill'=>[],'intent_routing'=>$routing];
        });
    };
    $beforeChat=Workflow::read($tenant,$user,$canvas,$thread);
    $beforeChatRevision=(int)Db::name(GraphService::TABLE)->where('id',$canvas)->value('graph_revision');
    $chatAck=$enqueueActive('workflow-unrelated-chat','你好，解释一下什么是蒙太奇？');
    $chatContext=json_decode((string)Db::name(Store::PREFIX.'run')->where('id',$chatAck['run_id'])->value('context_snapshot'),true);
    agentCheck(empty($chatContext['workflow']) && ($chatContext['intent_routing']['kind']??'')==='active_workflow',
        'active workflow turn is classified before its stage is committed');
    $provider->content=json_encode(['intent'=>'chat','confidence'=>0.98,'skill_key'=>'','reply_markdown'=>'蒙太奇是通过镜头的组合表达时间、情绪或意义。','workflow_output'=>null],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    agentCheck(Worker::process($tenant,$user,(int)$chatAck['run_id'],$provider)==='success'
        && ($provider->lastRequest['response_format']['type']??'')==='json_object'
        && ($provider->lastRequest['enable_thinking']??true)===false,
        'unrelated chat uses one structured text Provider turn without a second classification call');
    $afterChat=Workflow::read($tenant,$user,$canvas,$thread);
    agentCheck(($afterChat['workflow']['state_revision']??0)===($beforeChat['workflow']['state_revision']??-1)
        && ($afterChat['workflow']['stage_state']??[])===($beforeChat['workflow']['stage_state']??[])
        && (int)Db::name(GraphService::TABLE)->where('id',$canvas)->value('graph_revision')===$beforeChatRevision,
        'off-topic chat preserves the frozen workflow and graph exactly');
    $routing=(array)($chatContext['intent_routing']??[]);
    try { WorkflowTurn::parse('{"intent":"chat","confidence":1,"skill_key":"","reply_markdown":"已创建","workflow_output":{"canvas_actions":{"nodes":[]}}}',$routing); throw new RuntimeException('off-topic graph payload accepted'); }
    catch (RuntimeException $error) { agentCheck($error->getMessage()==='INVALID_AGENT_INTENT','off-topic model output cannot carry workflow actions'); }
    agentCheck(WorkflowTurn::failureCategory('not json',$routing)==='intent_not_json'
        && WorkflowTurn::failureCategory('{"intent":"chat","confidence":1,"skill_key":"","reply_markdown":"已创建","workflow_output":{"canvas_actions":{"nodes":[]}}}',$routing)==='intent_unexpected_output'
        && WorkflowTurn::failureCategory('{"intent":"continue","confidence":0.9,"skill_key":"","reply_markdown":"摘要","workflow_output":{}}',$routing)==='intent_continue_reply',
        'rejected model output is classified by safe shape only, without storing the answer');
    agentCheck(WorkflowTurn::failureCategory('{"intent":"continue","confidence":0.9,"skill_key":"","reply_markdown":"","workflow_output":{"reply_markdown":"美术规划","nodes":[]}}',$routing)==='intent_stage_keys'
        && WorkflowTurn::failureCategory('{"intent":"continue","confidence":0.9,"skill_key":"","reply_markdown":"","workflow_output":{"reply_markdown":"美术规划","canvas_actions":{"nodes":[]}}}',$routing)==='intent_stage_nodes',
        'workflow diagnostic distinguishes envelope and node-shape failures without persisting generated prose');
    agentCheck(!str_contains(ActionPlan::nestedInstruction('manual','art',true),'<canvas-actions>')
        && str_contains(ActionPlan::nestedInstruction('manual','art',true),'workflow_output 子对象')
        && !str_contains(ActionPlan::nestedInstruction('manual','assets',true),'<canvas-actions>'),
        'nested workflow instructions do not contradict the one-response intent envelope');
    $other=WorkflowTurn::parse(json_encode(['intent'=>'image','confidence'=>0.94,'skill_key'=>'',
        'reply_markdown'=>'这是一项单独的图片需求。','workflow_output'=>null],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$routing);
    agentCheck(!$other['continue'] && $other['nodes']===[] && $other['text']==='这是一项单独的图片需求。',
        'a separate image intent does not enter the short-drama stage or create a graph proposal');
    $artReply='美术规划已完成。<canvas-actions>{"nodes":[{"type":"text","artifact":"art_bible","title":"美术圣经","prompt":"art_bible: 电影写实，冷蓝雨夜与暖黄室内对照。","key":"art"},{"type":"text","artifact":"character_asset_spec","title":"主体资产设定","prompt":"character_asset_spec: 林夏短发风衣、录音笔。\nsubject_image_prompt: 都市悬疑女记者，电影写实。","key":"character"},{"type":"text","artifact":"scene_asset_spec","title":"场景资产设定","prompt":"scene_asset_spec: 雨夜办公室与旧档案室。\nscene_image_prompt: 雨夜办公室，冷蓝霓虹。","key":"scene"},{"type":"text","artifact":"prop_asset_spec","title":"道具资产设定","prompt":"prop_asset_spec: 可录音的旧式金属录音笔。","key":"prop"},{"type":"text","artifact":"three_view_prompt","title":"主体三视图提示词","prompt":"three_view_prompt: 同一林夏正侧背三视图，保持风衣与录音笔一致。","key":"views"}]}</canvas-actions>';
    $emptyReferencePlan=ActionPlan::parse('{"reply_markdown":"美术规划","canvas_actions":{"nodes":[{"type":"text","artifact":"art_bible","title":"画风","prompt":"旧书店冷暖对比","key":"art","reference_keys":[]}]}}','art',true);
    agentCheck(count($emptyReferencePlan['nodes'])===1 && !isset($emptyReferencePlan['nodes'][0]['reference_keys']),
        'empty optional reference lists are normalized to no graph edge while nonempty references remain validated');
    $planningOnly=ActionPlan::parse('{"reply_markdown":"美术规划","canvas_actions":{"nodes":[{"type":"text","artifact":"art_bible","title":"画风","prompt":"旧书店冷暖对比","key":"art","depends_on":["story_setting"],"reference_keys":["story_setting"]}]}}','art',true);
    agentCheck(count($planningOnly['nodes'])===1 && !isset($planningOnly['nodes'][0]['depends_on']) && !isset($planningOnly['nodes'][0]['reference_keys']),
        'compact conversation-only art planning cannot create model-invented graph references');
    $artPlan=ActionPlan::parse($artReply,'art');
    $continueAck=$enqueueActive('workflow-art-resume','继续刚才的短剧美术规划');
    $provider->content=json_encode(['intent'=>'continue','confidence'=>0.96,'skill_key'=>'','reply_markdown'=>'',
        'workflow_output'=>['reply_markdown'=>$artPlan['text'],'canvas_actions'=>['nodes'=>$artPlan['nodes']]]],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    agentCheck(Worker::process($tenant,$user,(int)$continueAck['run_id'],$provider)==='success','a later related turn resumes and validates the original art-stage contract');
    $afterArt=Workflow::read($tenant,$user,$canvas,$thread);
    agentCheck(($afterArt['workflow']['stage_state']['key']??'')==='art' && ($afterArt['workflow']['stage_state']['status']??'')==='awaiting_stage_confirmation','art reply remains reviewable until its structured asset plan is confirmed');
    $artContext=(string)($provider->lastRequest['messages'][0]['content']??'');
    agentCheck(str_contains($artContext,'confirmed_artifacts') && str_contains($artContext,'雨夜回音') && !str_contains($artContext,'这是本阶段的本地验收回复。'),'later Skills receive compact confirmed graph artifacts rather than replaying the full chat transcript');
    $history=Store::messages($tenant,$user,$canvas,$thread);
    $workflowMessages=array_values(array_filter($history,static fn(array $message): bool => ($message['role']??'')==='assistant' && !empty($message['workflow_timeline'])));
    agentCheck(count($workflowMessages)>=2 && ($workflowMessages[0]['workflow_timeline'][0]['kind']??'')==='skill','published assistant replies expose only server-derived Skill/tool timeline evidence');
    $pausedAck=$enqueueActive('workflow-confirmation-chat','顺便解释一下什么是镜头语言');
    $provider->content=json_encode(['intent'=>'chat','confidence'=>0.96,'skill_key'=>'','reply_markdown'=>'镜头语言是用景别、角度和运动表达叙事。','workflow_output'=>null],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    agentCheck(Worker::process($tenant,$user,(int)$pausedAck['run_id'],$provider)==='success'
        && (Workflow::read($tenant,$user,$canvas,$thread)['workflow']['state_revision']??0)===($afterArt['workflow']['state_revision']??-1),
        'ordinary chat remains available during confirmation without dismissing or advancing the card');
    $artConfirmed=Workflow::confirmStagePlan($tenant,$user,$canvas,$thread,(int)$afterArt['workflow']['state_revision']);
    agentCheck(($artConfirmed['workflow']['stage_state']['key']??'')==='assets' && ($artConfirmed['workflow']['stage_state']['status']??'')==='ready' && count($artConfirmed['canvas_actions']['nodes']??[])===5,'confirmed art plan writes its asset specifications before image planning');
    $artNodes=json_decode((string)Db::name(GraphService::TABLE)->where('id',$canvas)->value('nodes_json'),true);
    agentCheck(count($artNodes)===8 && ($artNodes[3]['metadata']['workflow_artifact']??'')==='art_bible' && str_contains((string)($artNodes[3]['metadata']['content']??''),'电影写实'),'art stage writes durable art direction and image-prompt text nodes');
    $assetReply='主体资产计划已完成。<canvas-actions>{"nodes":[{"type":"image","artifact":"subject","title":"女主主体图","prompt":"都市悬疑女记者，电影写实","key":"subject","reference_keys":["art:character"]},{"type":"image","artifact":"three_view","title":"女主三视图","prompt":"同一女记者正侧背三视图，电影写实","key":"three_view","depends_on":["subject"],"reference_keys":["art:views"]}]}</canvas-actions>';
    $assetPlan=ActionPlan::parse($assetReply,'assets',true);
    $assetAck=$enqueueActive('workflow-assets-resume','继续主体资产生图规划');
    $provider->content=json_encode(['intent'=>'continue','confidence'=>0.96,'skill_key'=>'','reply_markdown'=>'',
        'workflow_output'=>['reply_markdown'=>$assetPlan['text'],'canvas_actions'=>['nodes'=>$assetPlan['nodes']]]],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    agentCheck(Worker::process($tenant,$user,(int)$assetAck['run_id'],$provider)==='success','routed auto image stage reaches the existing quote-and-confirm boundary');
    $afterAssets=Workflow::read($tenant,$user,$canvas,$thread);
    agentCheck(($afterAssets['workflow']['stage_state']['key']??'')==='assets' && ($afterAssets['workflow']['stage_state']['status']??'')==='awaiting_plan_confirmation','auto asset reply is held as a priced plan before graph creation');
    agentCheck(preg_match('/^[a-f0-9]{64}$/D',(string)($afterAssets['workflow']['plan_hash']??''))===1 && ($afterAssets['workflow']['image_plan']['node_count']??0)===2 && ($afterAssets['workflow']['image_plan']['estimated_user_charge_points']??0)===10.0,'plan hash binds the real bounded proposals and aggregate estimate');
    $beforeImageConfirmation=json_decode((string)Db::name(GraphService::TABLE)->where('id',$canvas)->value('nodes_json'),true);
    agentCheck(WorkflowImageQuoteFixture::$calls===2 && count($beforeImageConfirmation)===8 && !array_filter($beforeImageConfirmation,static fn(array $node): bool => ($node['type']??'')==='image'),'estimate is local-only and leaves only already-approved text artifacts on the graph until image confirmation');
    try {
        Workflow::prepare($tenant,Db::name(Store::PREFIX.'thread')->where('id',$thread)->find(),'attempt to bypass plan confirmation',[],[],['generation_mode'=>'auto']);
        throw new RuntimeException('workflow message bypassed plan confirmation');
    } catch (RuntimeException $error) { agentCheck($error->getMessage()==='WORKFLOW_PLAN_CONFIRMATION_REQUIRED','unconfirmed auto plan blocks the next Agent request'); }
    $confirmed=Workflow::confirmPlan($tenant,$user,$canvas,$thread,(int)$afterAssets['workflow']['state_revision'],(string)$afterAssets['workflow']['plan_hash']);
    agentCheck(($confirmed['workflow']['plan_confirmation']['status']??'')==='confirmed' && ($confirmed['workflow']['stage_state']['key']??'')==='storyboard' && ($confirmed['canvas_actions']['mode']??'')==='auto','confirmed exact plan atomically creates the auto image graph batch and advances stage');
    $allAfterAssets=json_decode((string)Db::name(GraphService::TABLE)->where('id',$canvas)->value('nodes_json'),true);
    $assetNodes=array_values(array_filter($allAfterAssets,static fn(array $node): bool => ($node['metadata']['workflow_source_stage']??'')==='assets'));
    agentCheck(count($assetNodes)===2 && !empty($assetNodes[0]['metadata']['agent_auto_submit']) && !empty($assetNodes[1]['metadata']['agent_auto_submit']),'confirmed plan nodes carry server-owned auto request keys only after confirmation');
    $assetEdges=json_decode((string)Db::name(GraphService::TABLE)->where('id',$canvas)->value('edges_json'),true);
    agentCheck(count(array_filter($assetEdges,static fn(array $edge): bool => ($edge['role']??'')==='agent_dependency'))===3,'text artifacts and subject/three-view retain server-owned dependency edges');
    $assetReferenceEdges=array_values(array_filter($assetEdges,static fn(array $edge): bool => ($edge['role']??'')==='workflow_reference'));
    $assetReferenceSources=array_map(static fn(array $edge): string => (string)$edge['from'],$assetReferenceEdges);
    agentCheck(count($assetReferenceEdges)===2 && count(array_intersect($assetReferenceSources,[(string)$artNodes[4]['id'],(string)$artNodes[7]['id']]))===2,'each subject asset receives only its explicitly selected character or three-view reference, not every historic workflow artifact');
    $subjectContext=Canvas::agentAutoDependencyState($allAfterAssets,$assetEdges,(string)$assetNodes[0]['id']);
    agentCheck(($subjectContext['state']??'')==='ready' && str_contains((string)($subjectContext['text_context'][0]['content']??''),'character_asset_spec'),'a linked workflow text artifact is a bounded real generation input rather than a decorative graph edge');
    GraphService::patch($tenant,$user,$canvas,['expected_revision'=>(int)Db::name(GraphService::TABLE)->where('id',$canvas)->value('graph_revision'),'request_key'=>'legacy-workflow-overlink','operations'=>[['op'=>'add_edge','edge'=>['from'=>$scriptNodes[0]['id'],'to'=>$assetNodes[0]['id'],'kind'=>'reference','role'=>'legacy','order'=>999]]]]);
    $repair=GraphService::repairLegacyAgentAssetReferences($tenant,$user,$canvas);
    $allAfterAssets=json_decode((string)Db::name(GraphService::TABLE)->where('id',$canvas)->value('nodes_json'),true);
    $assetEdges=json_decode((string)Db::name(GraphService::TABLE)->where('id',$canvas)->value('edges_json'),true);
    $assetNodes=array_values(array_filter($allAfterAssets,static fn(array $node): bool => ($node['metadata']['workflow_source_stage']??'')==='assets'));
    $repairedSubjectInputs=array_values(array_filter($assetEdges,static fn(array $edge): bool => (string)($edge['to']??'')===(string)$assetNodes[0]['id']));
    $repairedThreeViewInputs=array_values(array_filter($assetEdges,static fn(array $edge): bool => (string)($edge['to']??'')===(string)$assetNodes[1]['id']));
    agentCheck(!empty($repair['changed']) && ($repair['removed']??0)>=4 && ($repair['added']??0)>=3 && count($repairedSubjectInputs)===1 && count($repairedThreeViewInputs)===2,'legacy Agent asset graph repair removes broad workflow inputs and restores only scoped subject/three-view references');
    $byWorkflowNode=[];
    foreach ($allAfterAssets as $node) $byWorkflowNode[(string)($node['id']??'')]=$node;
    $legacySemanticIndex=null;
    foreach ($assetEdges as $index=>$edge) {
        $source=(array)($byWorkflowNode[(string)($edge['from']??'')]??[]);
        $target=(array)($byWorkflowNode[(string)($edge['to']??'')]??[]);
        if (($source['metadata']['workflow_source_stage']??'')==='script' && ($target['metadata']['workflow_source_stage']??'')==='script') {$legacySemanticIndex=$index;break;}
    }
    agentCheck($legacySemanticIndex!==null,'fixture contains a workflow-owned prerequisite edge for semantic recovery');
    unset($assetEdges[$legacySemanticIndex]['kind'],$assetEdges[$legacySemanticIndex]['role'],$assetEdges[$legacySemanticIndex]['order']);
    Db::name(GraphService::TABLE)->where('id',$canvas)->update(['edges_json'=>json_encode($assetEdges,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    $semanticRepair=GraphService::repairLegacyAgentAssetReferences($tenant,$user,$canvas);
    $semanticEdges=json_decode((string)Db::name(GraphService::TABLE)->where('id',$canvas)->value('edges_json'),true);
    $semanticEdge=$semanticEdges[$legacySemanticIndex]??[];
    agentCheck(($semanticRepair['normalized']??0)>=1 && ($semanticEdge['kind']??'')==='reference' && ($semanticEdge['role']??'')==='agent_dependency' && isset($semanticEdge['order']),'legacy browser saves restore the semantic role/order of workflow-owned prerequisite edges without changing user edges');
    $healthyRevision=(int)Db::name(GraphService::TABLE)->where('id',$canvas)->value('graph_revision');
    $idempotentRepair=GraphService::repairLegacyAgentAssetReferences($tenant,$user,$canvas);
    agentCheck(empty($idempotentRepair['changed']) && ($idempotentRepair['graph_revision']??0)===$healthyRevision,'a healthy repaired graph does not receive another version-changing rewrite during verification');
    try {
        ActionPlan::parse('<canvas-actions>{"nodes":[{"type":"image","artifact":"three_view","title":"孤立三视图","prompt":"invalid","key":"three_view"}]}</canvas-actions>','assets');
        throw new RuntimeException('three view without subject was accepted');
    } catch (RuntimeException $error) { agentCheck($error->getMessage()==='INVALID_AGENT_ACTION','workflow rejects a three-view without its subject dependency'); }
    $storyboardPlan=ActionPlan::parse('场景与分镜图已规划。<canvas-actions>{"nodes":[{"type":"image","artifact":"scene","title":"雨夜办公室","prompt":"雨夜办公室，电影写实","key":"scene_1","reference_keys":["art:scene"]},{"type":"image","artifact":"prop","title":"旧录音笔","prompt":"旧录音笔特写","key":"prop_1","reference_keys":["art:prop"]},{"type":"image","artifact":"storyboard","title":"分镜 01","prompt":"女主手握录音笔进入办公室","key":"board_1","depends_on":["scene_1","prop_1"],"reference_keys":["assets:subject","assets:three_view"]}]}</canvas-actions>','storyboard');
    $storyboardWorkflow=$confirmed['workflow'];$storyboardWorkflow['stage_state']=['key'=>'storyboard','status'=>'running'];
    $storyboardEffects=Db::transaction(static function () use ($canvas,$storyboardPlan,$storyboardWorkflow): array {
        $document=Db::name(GraphService::TABLE)->where('id',$canvas)->lock(true)->find();
        return GraphService::appendAgentNodesLocked($document,$storyboardPlan['nodes'],[],true,[],1,$storyboardWorkflow);
    });
    $storyboardId=(string)$storyboardEffects['nodes'][2]['id'];
    $storyboardEdges=json_decode((string)Db::name(GraphService::TABLE)->where('id',$canvas)->value('edges_json'),true);
    $storyboardInputs=array_map(static fn(array $edge): string => (string)$edge['from'],array_filter($storyboardEdges,static fn(array $edge): bool => (string)($edge['to']??'')===$storyboardId));
    agentCheck(count(array_intersect($storyboardInputs,[(string)$assetNodes[0]['id'],(string)$assetNodes[1]['id'],(string)$storyboardEffects['nodes'][0]['id'],(string)$storyboardEffects['nodes'][1]['id']]))>=4,'storyboard receives real subject, three-view, scene and prop reference edges');
    $videoPlanText=ActionPlan::parse('视频规划已完成。<canvas-actions>{"nodes":[{"type":"text","artifact":"video_prompt_plan","title":"分镜 01 视频提示词","prompt":"shot_number: 01\nduration: 5秒\nfirst_frame: 林夏在办公室。\nlast_frame: 手机亮起匿名短信。\ncamera_motion: 缓慢推进。\naction_sequence: 播放录音后看向手机。\nvideo_prompt: 雨夜办公室，镜头缓慢推进。\nasset_references: 林夏、雨夜办公室、录音笔。","key":"video_plan_1"}]}</canvas-actions>','video_plan');
    $videoPlanWorkflow=$confirmed['workflow'];$videoPlanWorkflow['stage_state']=['key'=>'video_plan','status'=>'running'];
    $videoPlanEffects=Db::transaction(static function () use ($canvas,$videoPlanText,$videoPlanWorkflow): array {
        $document=Db::name(GraphService::TABLE)->where('id',$canvas)->lock(true)->find();
        return GraphService::appendAgentNodesLocked($document,$videoPlanText['nodes'],[],false,[],1,$videoPlanWorkflow);
    });
    agentCheck(count($videoPlanEffects['nodes'])===1 && $videoPlanEffects['nodes'][0]['type']==='text','video planning writes a durable video-prompt text node before manual video nodes');
    $videoPlan=ActionPlan::parse('分镜视频节点已规划。<canvas-actions>{"nodes":[{"type":"video","artifact":"storyboard_video","title":"分镜 01","prompt":"角色推门进入办公室","key":"shot_1","reference_keys":["storyboard:board_1","assets:subject","video_plan:video_plan_1"]},{"type":"video","artifact":"storyboard_video","title":"分镜 02","prompt":"角色回头看向窗外","key":"shot_2","depends_on":["shot_1"],"reference_keys":["storyboard:board_1","assets:subject","video_plan:video_plan_1"]}]}</canvas-actions>','video_nodes');
    agentCheck(count($videoPlan['nodes'])===2 && $videoPlan['nodes'][1]['type']==='video','video stage accepts one bounded batch of storyboard video nodes');
    $videoWorkflow=$confirmed['workflow'];$videoWorkflow['stage_state']=['key'=>'video_nodes','status'=>'running'];
    $videoEffects=Db::transaction(static function () use ($canvas,$videoPlan,$videoWorkflow): array {
        $document=Db::name(GraphService::TABLE)->where('id',$canvas)->lock(true)->find();
        return GraphService::appendAgentNodesLocked($document,$videoPlan['nodes'],[],true,[],1,$videoWorkflow);
    });
    agentCheck(!array_filter($videoEffects['nodes'],static fn(array $node): bool => $node['auto_submit']),'storyboard videos are inserted but never auto-submitted');
    $videoId=(string)$videoEffects['nodes'][0]['id'];
    $videoEdges=json_decode((string)Db::name(GraphService::TABLE)->where('id',$canvas)->value('edges_json'),true);
    $videoInputs=array_map(static fn(array $edge): string => (string)$edge['from'],array_filter($videoEdges,static fn(array $edge): bool => (string)($edge['to']??'')===$videoId));
    agentCheck(in_array($storyboardId,$videoInputs,true) && in_array((string)$assetNodes[0]['id'],$videoInputs,true),'storyboard video receives durable storyboard and subject reference edges');
    $audioPlan=ActionPlan::parse('音频规划已插入。<canvas-actions>{"nodes":[{"type":"audio","artifact":"audio_plan","title":"音频规划（暂未开放）","prompt":"片头音乐与角色对白节奏规划","key":"audio_plan"}]}</canvas-actions>','audio_plan');
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
    $completeSettings=json_decode((string)Db::name(Store::PREFIX.'thread')->where('id',$thread)->value('settings_json'),true,512,JSON_THROW_ON_ERROR);
    $completeSettings['workflow_state']['stage_state']=['key'=>'complete','status'=>'ready','completed'=>['intake','script','art','assets','storyboard','video_plan','video_nodes','audio_plan']];
    Db::name(Store::PREFIX.'thread')->where('id',$thread)->update(['settings_json'=>json_encode($completeSettings,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
    $completeCard=Workflow::read($tenant,$user,$canvas,$thread)['card'];
    agentCheck(($completeCard['type']??'')==='complete' && ($completeCard['stage_index']??0)===($completeCard['stage_total']??-1),'finished workflow shows a complete 8/8 card instead of resetting to 0/8');
    try { Workflow::read($tenant+1,$user,$canvas,$thread); throw new RuntimeException('cross tenant workflow read passed'); }
    catch (RuntimeException $error) { agentCheck($error->getMessage()==='CANVAS_NOT_FOUND','workflow state cannot be read across tenants'); }
    // A greeting stays chat. An explicit whole-production request then uses
    // the modern semantic router rather than a keyword shortcut.
    $intentThread=Store::create($tenant,$user,$canvas,'intent-thread')['id'];
    agentCheck(!IntentRouter::shouldClassify($tenant,'你好',[],[]) && IntentRouter::shouldClassify($tenant,'重生之我在天庭当人事的一天',[],[]),'greeting remains ordinary chat while a story premise reaches semantic classification');
    $revision=(int)Db::name(GraphService::TABLE)->where('id',$canvas)->value('graph_revision');
    $helloAck=Store::enqueue($tenant,$user,$canvas,$intentThread,['request_key'=>'intent-hello','content'=>'你好','base_revision'=>$revision],['settings'=>['generation_mode'=>'manual'],'skill'=>[]]);
    $provider->content='你好，请告诉我你的创作想法。';
    agentCheck(Worker::process($tenant,$user,(int)$helloAck['run_id'],$provider)==='success','ordinary greeting remains a normal Agent reply');
    $questionAck=Store::enqueue($tenant,$user,$canvas,$intentThread,['request_key'=>'intent-video-script-question','content'=>'你能帮我生成一个视频脚本吗？','base_revision'=>$revision],static function (array $conversation) use ($tenant): array {
        $candidate=Workflow::prepare($tenant,$conversation,'/short-drama',[],[],['generation_mode'=>'manual'])['workflow'];
        $candidate['workflow_snapshot']['route']='semantic';
        return ['settings'=>['generation_mode'=>'manual'],'skill'=>[],
            'intent_routing'=>IntentRouter::snapshot($tenant)+['workflow_candidate'=>$candidate]];
    });
    $provider->content=json_encode(['intent'=>'chat','confidence'=>0.96,'skill_key'=>'',
        'reply_markdown'=>'可以。你希望这个视频脚本讲什么主题？','intake'=>['candidates'=>[],'questions'=>[]],
        'speech_act'=>'question','deliverable'=>'text','scope'=>'conversation'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    agentCheck(Worker::process($tenant,$user,(int)$questionAck['run_id'],$provider)==='success'
        && Workflow::currentState(Db::name(Store::PREFIX.'thread')->where('id',$intentThread)->find())===[]
        && (int)Db::name(GraphService::TABLE)->where('id',$canvas)->value('graph_revision')===$revision,
        'video-script capability question answers in chat without starting the drama workflow or changing the graph');
    $productAck=Store::enqueue($tenant,$user,$canvas,$intentThread,['request_key'=>'intent-product-script','content'=>'耳机，主要功能是降噪、环绕立体音','base_revision'=>$revision],static function (array $conversation) use ($tenant): array {
        $candidate=Workflow::prepare($tenant,$conversation,'/short-drama',[],[],['generation_mode'=>'manual'])['workflow'];
        $candidate['workflow_snapshot']['route']='semantic';
        return ['settings'=>['generation_mode'=>'manual'],'skill'=>[],
            'intent_routing'=>IntentRouter::snapshot($tenant)+['workflow_candidate'=>$candidate]];
    });
    $productReply='耳机宣传脚本：第一镜展示通勤降噪，第二镜呈现环绕立体音。';
    $provider->content=json_encode(['intent'=>'creative_plan','confidence'=>'0.93',
        'reply_markdown'=>$productReply,'reasoning'=>'standalone text request'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    agentCheck(Worker::process($tenant,$user,(int)$productAck['run_id'],$provider)==='success'
        && (json_decode((string)Db::name(Store::PREFIX.'message')->where(['run_id'=>$productAck['run_id'],'role'=>'assistant'])->value('content_json'),true)['text']??'')===$productReply
        && Workflow::currentState(Db::name(Store::PREFIX.'thread')->where('id',$intentThread)->find())===[]
        && (int)Db::name(GraphService::TABLE)->where('id',$canvas)->value('graph_revision')===$revision,
        'incomplete ordinary product-script envelope still publishes real text without workflow or graph effects');
    $story='请把重生之我在天庭当人事的一天制作成完整短剧';
    $routing=IntentRouter::snapshot($tenant);
    $intentAck=Store::enqueue($tenant,$user,$canvas,$intentThread,['request_key'=>'intent-story','content'=>$story,'base_revision'=>$revision],static function (array $conversation) use ($tenant,$routing): array {
        $candidate=Workflow::prepare($tenant,$conversation,'/short-drama',[],[],['generation_mode'=>'manual'])['workflow'];
        $candidate['workflow_snapshot']['route']='semantic';
        return ['settings'=>['generation_mode'=>'manual'],'skill'=>[],'intent_routing'=>$routing+['workflow_candidate'=>$candidate]];
    });
    $intakeDraft=['candidates'=>[
        ['key'=>'genre','value'=>'天庭职场奇幻喜剧','source'=>'message','evidence'=>'重生之我在天庭当人事的一天','confidence'=>0.96],
    ],'questions'=>[
        ['key'=>'episode_count','ask'=>'这段天庭职场故事希望拍成几集？','options'=>['1集短片','10集微短剧']],
    ]];
    $provider->content=json_encode(['intent'=>'short_drama','confidence'=>0.94,'skill_key'=>'','reply_markdown'=>'这是一个天庭职场的故事创意。','intake'=>$intakeDraft,
        'speech_act'=>'request','deliverable'=>'full_drama','scope'=>'workflow'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    agentCheck(Worker::process($tenant,$user,(int)$intentAck['run_id'],$provider)==='success','one classified text run activates the frozen workflow without a second Provider call');
    $intentView=Workflow::read($tenant,$user,$canvas,$intentThread);
    agentCheck(($intentView['workflow']['workflow_snapshot']['route']??'')==='semantic'
        && ($intentView['workflow']['stage_state']['key']??'')==='intake'
        && ($intentView['card']['type']??'')==='intake_review'
        && ($intentView['card']['candidates'][0]['source']??'')==='message'
        && ($intentView['workflow']['slot_values']??[])===[],'semantic route projects an unconfirmed, source-labelled draft without auto-accepting its facts');
    agentCheck((int)Db::name(GraphService::TABLE)->where('id',$canvas)->value('graph_revision')===$revision
        && ($provider->lastRequest['response_format']['type']??'')==='json_object'
        && ($provider->lastRequest['max_tokens']??0)===4096,'classification turn uses bounded structured output and does not mutate the graph');
    try { Workflow::prepare($tenant,Db::name(Store::PREFIX.'thread')->where('id',$intentThread)->find(),'继续',[],[],[]); throw new RuntimeException('unreviewed draft bypassed'); }
    catch (RuntimeException $error) { agentCheck($error->getMessage()==='WORKFLOW_INTAKE_REVIEW_REQUIRED','unreviewed material cannot advance the workflow'); }
    try { Workflow::answer($tenant,$user,$canvas,$intentThread,(int)$intentView['workflow']['state_revision'],'intake_review','{"genre":"修订","audience":"任意"}'); throw new RuntimeException('undeclared candidate accepted'); }
    catch (RuntimeException $error) { agentCheck($error->getMessage()==='INVALID_WORKFLOW_ANSWER','review cannot confirm a slot that the model did not propose'); }
    $intentView=Workflow::answer($tenant,$user,$canvas,$intentThread,(int)$intentView['workflow']['state_revision'],'intake_review','{"genre":"奇幻职场喜剧"}');
    agentCheck(($intentView['workflow']['slot_values']['genre']??'')==='奇幻职场喜剧'
        && ($intentView['card']['slot']['key']??'')==='episode_count'
        && ($intentView['card']['slot']['ask']??'')==='这段天庭职场故事希望拍成几集？',
        'owner correction persists and the next card asks only the missing, contextual question');
    $currentState=$intentView['workflow'];
    $repeat=Workflow::withIntakeDraft($currentState,$intakeDraft);
    agentCheck(($repeat['stage_state']['status']??'')==='collecting' && ($repeat['intake_candidates']??[])===[],
        'later extraction does not reopen an already confirmed slot');
    $documentDraft=['candidates'=>[['key'=>'characters','value'=>'记者与失踪姐姐','source'=>'document','evidence'=>'记者追查姐姐','confidence'=>0.9]],'questions'=>[]];
    try { IntakeDraft::parse($documentDraft,Workflow::catalog()['slots']); throw new RuntimeException('invented document provenance accepted'); }
    catch (RuntimeException $error) { agentCheck($error->getMessage()==='INVALID_AGENT_INTAKE','model cannot claim document extraction without an actual parsed document'); }
    $inlineContext=['messages'=>[['role'=>'user','content'=>'请继续','attachments'=>[['type'=>'text','name'=>'idea.txt','content'=>'记者追查姐姐']]]]];
    agentCheck(in_array('document',IntakeDraft::availableSources($inlineContext),true)
        && (IntakeDraft::parse($documentDraft,Workflow::catalog()['slots'],IntakeDraft::availableSources($inlineContext))['candidates']['characters']['value']??'')==='记者与失踪姐姐',
        'parsed user text enables a source-labelled draft while still requiring owner review');
    $directThread=Store::create($tenant,$user,$canvas,'direct-intake-thread')['id'];
    $directAck=Store::enqueue($tenant,$user,$canvas,$directThread,['request_key'=>'direct-intake','content'=>'/short-drama 我想拍天庭职场短剧','base_revision'=>$revision],static function (array $conversation) use ($tenant): array {
        $preferences=['generation_mode'=>'manual','reasoning_model'=>['id'=>'fixture-model']];
        $prepared=Workflow::prepare($tenant,$conversation,'/short-drama 我想拍天庭职场短剧',[],[],$preferences);
        return ['settings'=>$preferences,'skill'=>[],'workflow'=>$prepared['workflow'],'thread_settings'=>$prepared['thread_settings']];
    });
    $provider->content=json_encode(['reply_markdown'=>'先核对我从描述里读出的设定。','intake'=>$intakeDraft],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    agentCheck(Worker::process($tenant,$user,(int)$directAck['run_id'],$provider)==='success'
        && ($provider->lastRequest['max_tokens']??0)===1500,'direct /short-drama intake uses one bounded structured Provider turn');
    $directView=Workflow::read($tenant,$user,$canvas,$directThread);
    agentCheck(($directView['card']['type']??'')==='intake_review'
        && ($directView['workflow']['slot_values']??[])===[]
        && (int)Db::name(GraphService::TABLE)->where('id',$canvas)->value('graph_revision')===$revision,
        'direct intake preserves review-before-accept and creates no canvas nodes');
    $documentThread=Store::create($tenant,$user,$canvas,'document-intake-thread')['id'];
    $documentAttachment=[['type'=>'text','name'=>'idea.txt','content'=>'主角是一位记者，追查失踪的姐姐。']];
    $documentAck=Store::enqueue($tenant,$user,$canvas,$documentThread,['request_key'=>'document-intake','content'=>'/short-drama 按附件创作短剧','base_revision'=>$revision,'attachments'=>$documentAttachment],static function (array $conversation) use ($tenant,$documentAttachment): array {
        $preferences=['generation_mode'=>'manual','reasoning_model'=>['id'=>'fixture-model']];
        $prepared=Workflow::prepare($tenant,$conversation,'/short-drama 按附件创作短剧',[],$documentAttachment,$preferences);
        return ['settings'=>$preferences,'skill'=>[],'workflow'=>$prepared['workflow'],'thread_settings'=>$prepared['thread_settings']];
    });
    $provider->content=json_encode(['reply_markdown'=>'我先核对附件中的角色信息。','intake'=>$documentDraft],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    agentCheck(Worker::process($tenant,$user,(int)$documentAck['run_id'],$provider)==='success'
        && str_contains((string)($provider->lastRequest['messages'][0]['content']??''),'追查失踪的姐姐'),
        'owned inline document text reaches the same real Worker contract as an inert attachment');
    $documentView=Workflow::read($tenant,$user,$canvas,$documentThread);
    agentCheck(($documentView['card']['candidates'][0]['source']??'')==='document'
        && ($documentView['workflow']['slot_values']??[])===[]
        && (int)Db::name(GraphService::TABLE)->where('id',$canvas)->value('graph_revision')===$revision,
        'document-derived candidate appears only as a review card without graph mutation');
    try { IntentRouter::parse('{"intent":"delete_all","confidence":1,"skill_key":"","reply_markdown":"ok"}',$routing); throw new RuntimeException('untrusted model intent accepted'); }
    catch (RuntimeException $error) { agentCheck($error->getMessage()==='INVALID_AGENT_INTENT','model cannot invent a route or executable action'); }
    try { IntentRouter::parse('{"intent":"image","confidence":0.9,"skill_key":"tenant_other_skill","reply_markdown":"ok"}',$routing); throw new RuntimeException('unowned model skill accepted'); }
    catch (RuntimeException $error) { agentCheck($error->getMessage()==='INVALID_AGENT_INTENT','model cannot recommend a skill absent from the tenant-visible snapshot'); }
    // A current-version thread reaches the script stage through the same
    // per-message router as the paid Provider. The older stage fixture above
    // deliberately freezes a legacy version and cannot cover this boundary.
    $compactThread=Store::create($tenant,$user,$canvas,'compact-active-script-thread')['id'];
    $compactRow=Db::name(Store::PREFIX.'thread')->where('id',$compactThread)->find();
    $compactPrepared=Workflow::prepare($tenant,$compactRow,'/short-drama',[],[],['generation_mode'=>'manual'])['thread_settings'];
    Db::name(Store::PREFIX.'thread')->where('id',$compactThread)->update(['settings_json'=>json_encode($compactPrepared,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
    foreach (['genre'=>'现代悬疑','episode_count'=>'1','episode_duration'=>'60秒','visual_style'=>'电影写实','aspect_ratio'=>'16:9','audience'=>'成年观众','characters'=>'年轻修书师','ending'=>'发现童年留言'] as $slot=>$value) {
        $current=Workflow::read($tenant,$user,$canvas,$compactThread);
        Workflow::answer($tenant,$user,$canvas,$compactThread,(int)$current['workflow']['state_revision'],$slot,$value);
    }
    $compactBefore=Workflow::read($tenant,$user,$canvas,$compactThread);
    $compactRevision=(int)Db::name(GraphService::TABLE)->where('id',$canvas)->value('graph_revision');
    $compactAck=Store::enqueue($tenant,$user,$canvas,$compactThread,['request_key'=>'compact-active-script','content'=>'继续刚才的剧本阶段','base_revision'=>$compactRevision],static function (array $conversation) use ($tenant): array {
        $current=Workflow::currentState($conversation);
        $candidate=Workflow::prepare($tenant,$conversation,'继续刚才的剧本阶段',[],[],['generation_mode'=>'manual'])['workflow'];
        $routing=IntentRouter::snapshot($tenant)+['kind'=>'active_workflow','workflow_candidate'=>$candidate,
            'base_workflow_revision'=>(int)$current['state_revision'],'workflow_paused'=>false];
        return ['settings'=>['generation_mode'=>'manual','reasoning_model'=>['id'=>'fixture-model']],'skill'=>[],'intent_routing'=>$routing];
    });
    $compactContext=json_decode((string)Db::name(Store::PREFIX.'run')->where('id',$compactAck['run_id'])->value('context_snapshot'),true);
    $recommendedKey=(string)($compactContext['intent_routing']['skill_candidates'][0]['key']??'');
    agentCheck($recommendedKey!=='','routed compact script freezes an authorized advisory Skill candidate');
    $provider->content=json_encode(['intent'=>'continue','confidence'=>0.96,'skill_key'=>$recommendedKey,'reply_markdown'=>'',
        'workflow_output'=>['reply_markdown'=>'已完成旧书店短剧的故事设定与单集剧本。','canvas_actions'=>['nodes'=>[
            ['type'=>'text','artifact'=>'story_setting','title'=>'故事设定','prompt'=>'旧书店的修书师发现童年留言，逐步揭开家庭秘密。','key'=>'story'],
            ['type'=>'text','artifact'=>'episode_script','title'=>'单集剧本','prompt'=>'场景一：修书师进入旧书店；场景二：发现童年留言并揭示真相。','key'=>'episode'],
        ]]],'speech_act'=>'request','deliverable'=>'full_drama','scope'=>'workflow'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    agentCheck(Worker::process($tenant,$user,(int)$compactAck['run_id'],$provider)==='success'
        && ($provider->lastRequest['response_format']['type']??'')==='json_object'
        && (string)Db::name(Store::PREFIX.'run')->where('id',$compactAck['run_id'])->value('skill_snapshot')==='[]',
        'current compact script accepts an advisory Skill key without executing a model-selected Skill');
    $compactAfter=Workflow::read($tenant,$user,$canvas,$compactThread);
    agentCheck(($compactBefore['workflow']['stage_state']['status']??'')==='ready'
        && ($compactAfter['workflow']['stage_state']['status']??'')==='awaiting_stage_confirmation'
        && (int)Db::name(GraphService::TABLE)->where('id',$canvas)->value('graph_revision')===$compactRevision,
        'current compact script remains a confirmable plan without premature canvas writes');
    $plain=Store::create($tenant,$user,$canvas,'plain-thread')['id'];
    $prepared=Workflow::prepare($tenant,['settings_json'=>'{}'],'/other-skill 请写一段文案',[],[],[]);
    agentCheck($prepared['workflow']===[],'unrelated explicit slash Skill does not enter the short-drama workflow');
} finally {
    if ($canvas) {
        foreach (['outbox','event','message','run','thread'] as $kind) Db::name(Store::PREFIX.$kind)->where(['canvas_id'=>$canvas,'tenant_id'=>$tenant,'user_id'=>$user])->delete();
        Db::name('aigc_short_drama_canvas')->where(['id'=>$canvas,'tenant_id'=>$tenant,'user_id'=>$user])->delete();
    }
    if ($config) Db::name('aigc_short_drama_config')->where(['id'=>$config,'tenant_id'=>$tenant])->delete();
    if ($stageSkill) {
        Db::name('aigc_short_drama_skill_version')->where(['tenant_id'=>$tenant,'skill_id'=>$stageSkill])->delete();
        Db::name('aigc_short_drama_skill')->where(['tenant_id'=>$tenant,'id'=>$stageSkill])->delete();
    }
}
echo "NOT_RUN real Provider, image plan quotation and paid media submission\n";
