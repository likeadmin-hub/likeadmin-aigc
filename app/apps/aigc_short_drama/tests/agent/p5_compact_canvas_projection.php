<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';

use app\common\service\app\aigc_short_drama\ShortDramaCanvasService as Canvas;
use app\common\service\app\aigc_short_drama\ShortDramaPromptCatalog;
use app\common\service\app\aigc_short_drama\ShortDramaPromptWorkspace;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationActionPlan as ActionPlan;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationCreativePrompt;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationExecution as Execution;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationProviderInterface;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationStore as Store;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationWorker as Worker;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationWorkflow as Workflow;
use app\common\service\app\aigc_short_drama\canvas_agent\GraphService;
use think\facade\Db;

final class CompactProjectionProvider implements ConversationProviderInterface
{
    public string $content='';
    public array $request=[];
    public function preflight(int $tenant,int $user,array $request): void {$this->request=$request;}
    public function generate(int $tenant,int $user,array $request): array
    {
        return ['content'=>$this->content,'tool_calls'=>[],'safety_checked'=>true];
    }
}

final class CompactProjectionQuote
{
    public static function estimate(int $tenant,array $params): array
    {
        return ['market_product_id'=>77,'market_sku_id'=>88,'tenant_cost_points'=>3,'user_charge_points'=>5,'usage_unit'=>'call','settlement_mode'=>'reserved'];
    }
}
class_alias(CompactProjectionQuote::class,'app\\common\\service\\app\\aigc_image\\AigcImageService');

$tenant=91058;$user=92058;$canvas=0;$config=0;
if (Db::name('aigc_short_drama_config')->where('tenant_id',$tenant)->count()) throw new RuntimeException('Existing compact fixture config');
$reply=static fn(array $nodes,string $summary): string=>json_encode(['reply_markdown'=>$summary,'canvas_actions'=>['nodes'=>$nodes]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
$graph=static function (int $canvas): array {
    $row=Db::name(GraphService::TABLE)->where('id',$canvas)->find();
    return [json_decode((string)$row['nodes_json'],true,512,JSON_THROW_ON_ERROR),json_decode((string)$row['edges_json'],true,512,JSON_THROW_ON_ERROR)];
};
try {
    $config=Db::name('aigc_short_drama_config')->insertGetId(['tenant_id'=>$tenant,'config_json'=>json_encode(['canvas_agent'=>['enabled'=>true,'workflow'=>['enabled'=>true,'enabled_workflows'=>[Workflow::KEY]]]],JSON_UNESCAPED_UNICODE),'status'=>1,'create_time'=>time(),'update_time'=>time()]);
    $frozenPrompt=ShortDramaPromptWorkspace::resolve($tenant,['mode'=>'workspace','overrides'=>['subject.character'=>'测试：主体必须正面居中，服饰一致']],[]);
    $sharedRules=ConversationCreativePrompt::forStage(['workflow_snapshot'=>['creative_prompt_snapshot'=>$frozenPrompt],'stage_state'=>['key'=>'assets']]);
    agentCheck(str_contains($sharedRules,'测试：主体必须正面居中，服饰一致') && !str_contains($sharedRules,ShortDramaPromptCatalog::defaults()['subject.character']),
        'Agent stage renders original short-drama prompt workspace overrides rather than a copied default');
    $documentPrompt=ShortDramaPromptWorkspace::resolve($tenant,['mode'=>'documents','document_settings'=>[
        'subject_image'=>['mode'=>'custom','body'=>"【适用：人物主体】\n测试：沿用正式短剧的角色参考图要求"],
    ]],[]);
    $documentRules=ConversationCreativePrompt::forStage(['workflow_snapshot'=>['creative_prompt_snapshot'=>$documentPrompt],'stage_state'=>['key'=>'assets']]);
    agentCheck(str_contains($documentRules,'测试：沿用正式短剧的角色参考图要求'),
        'Agent stage renders the same tenant document prompt mode used by the original short-drama flow');
    $canvas=Canvas::create($tenant,$user,['title'=>'Compact workflow projection'])['id'];
    $thread=Store::create($tenant,$user,$canvas,'compact-projection')['id'];
    $preferences=['generation_mode'=>'auto','reasoning_model'=>['id'=>'fixture-text'],'image_model'=>['id'=>'fixture-image','model_code'=>'fixture-image']];
    $accept=static function (string $requestKey,string $content,array $selected=[]) use ($tenant,$user,$canvas,$thread,$preferences): array {
        $revision=(int)Db::name(GraphService::TABLE)->where('id',$canvas)->value('graph_revision');
        return Store::enqueue($tenant,$user,$canvas,$thread,['request_key'=>$requestKey,'content'=>$content,'base_revision'=>$revision],static function (array $conversation) use ($tenant,$content,$selected,$preferences): array {
            $prepared=Workflow::prepare($tenant,$conversation,$content,$selected,[],$preferences);
            return ['settings'=>$preferences,'skill'=>[],'workflow'=>$prepared['workflow'],'thread_settings'=>$prepared['thread_settings']];
        });
    };
    $start=$accept('compact-start','/short-drama 请创作悬疑短剧');
    $publicStart=Workflow::read($tenant,$user,$canvas,$thread);
    agentCheck(!isset($publicStart['workflow']['workflow_snapshot']['creative_prompt_snapshot']),
        'frozen internal creative prompt configuration is not exposed in the public workflow snapshot');
    agentCheck(Execution::stop($tenant,$user,$canvas,$thread,(int)$start['run_id'])['status']==='canceled','compact fixture starts in collection without a media request');
    foreach (['genre'=>'悬疑反转','episode_count'=>'1集（短片）','episode_duration'=>'1分钟','visual_style'=>'电影写实','audience'=>'年轻女性','characters'=>'林夏与姐姐','ending'=>'反转开放'] as $slot=>$value) {
        $view=Workflow::read($tenant,$user,$canvas,$thread);
        Workflow::answer($tenant,$user,$canvas,$thread,(int)$view['workflow']['state_revision'],$slot,$value);
    }
    $provider=new CompactProjectionProvider();
    $runStage=static function (string $key,string $content,array $selected=[]) use ($provider,$accept,$tenant,$user,$canvas,$thread): array {
        $provider->content=$content;
        $ack=$accept($key,'继续当前创作阶段',$selected);
        agentCheck(Worker::process($tenant,$user,(int)$ack['run_id'],$provider)==='success','compact workflow Worker accepts '.$key);
        return Workflow::read($tenant,$user,$canvas,$thread);
    };

    $script=$reply([
        ['type'=>'text','artifact'=>'story_setting','title'=>'故事设定与大纲','prompt'=>'剧名：雨夜回音。林夏调查姐姐失踪。第一集：雨夜收到录音、追踪档案、结尾出现匿名短信。','key'=>'story','formal_fields'=>['title'=>'雨夜回音','type_judgement'=>'悬疑短剧','core_theme'=>'亲情与真相','story_outline'=>'林夏调查姐姐失踪，第一集在雨夜收到录音并追踪档案。']],
        ['type'=>'text','artifact'=>'episode_script','title'=>'第一集剧本','prompt'=>'场景一：雨夜办公室。林夏播放录音；对白：姐姐，你在哪里？场景二：档案室，手机收到匿名短信。','key'=>'episode_1','formal_fields'=>['episode_number'=>1,'title'=>'雨夜录音','story_outline'=>'林夏收到录音，追踪档案并收到匿名短信。','scene_script'=>'场景一：雨夜办公室。林夏播放录音；对白：姐姐，你在哪里？场景二：档案室，手机收到匿名短信。']],
    ],'真实的设定、分集大纲与当前单集剧本');
    $view=$runStage('compact-script',$script);
    agentCheck(($view['card']['plan']['node_count']??0)===2,'script card previews exactly two useful canvas outputs');
    Workflow::confirmStagePlan($tenant,$user,$canvas,$thread,(int)$view['workflow']['state_revision']);
    [$nodes,$edges]=$graph($canvas);
    agentCheck(count($nodes)===2 && count($edges)===0 && ($nodes[1]['metadata']['workflow_artifact']??'')==='episode_script','story/outline and episode script are the only text canvas nodes and have no decorative dependency edge');
    agentCheck(!isset($nodes[0]['metadata']['workflow_formal_fields']) && !isset($nodes[1]['metadata']['workflow_formal_fields']),
        'retired formal-project fields are not projected to new canvas nodes');
    $plainScript=$reply([
        ['type'=>'text','artifact'=>'story_setting','title'=>'故事设定','prompt'=>'林夏寻找姐姐','key'=>'plain_story'],
        ['type'=>'text','artifact'=>'episode_script','title'=>'第一集','prompt'=>'林夏收到录音','key'=>'bad_episode'],
    ],'自由正文');
    $parsed=ActionPlan::parse($plainScript,'script',true);
    agentCheck(count($parsed['nodes'])===2 && !isset($parsed['nodes'][0]['formal_fields']),
        'script stage accepts readable content without retired formal-project fields');
    agentCheck(!str_contains(ActionPlan::instruction('manual','script',true), 'formal_fields'),
        'new script stage no longer requests formal-project-only model fields');
    $legacy=ActionPlan::parse($script,'script',true);
    agentCheck(count($legacy['nodes'])===2 && !isset($legacy['nodes'][0]['formal_fields']),
        'in-flight legacy Provider fields are accepted but discarded');
    $storyId=(string)$nodes[0]['id'];

    $art=$reply([
        ['type'=>'text','artifact'=>'art_bible','title'=>'画风规划','prompt'=>'电影写实，雨夜冷蓝、室内暖黄，避免角色外貌漂移。','key'=>'style'],
        ['type'=>'text','artifact'=>'character_asset_spec','title'=>'林夏形象','prompt'=>'林夏短发、米色风衣、胸前别着录音笔，面容稳定。','key'=>'character'],
        ['type'=>'text','artifact'=>'three_view_prompt','title'=>'林夏三视图规划','prompt'=>'同一林夏的正面、侧面、背面，米色风衣和录音笔一致。','key'=>'views'],
        ['type'=>'text','artifact'=>'scene_asset_spec','title'=>'雨夜办公室','prompt'=>'雨夜办公室，窗外冷蓝霓虹，桌灯暖黄。','key'=>'scene'],
    ],'已在对话中规划画风、角色与场景');
    $view=$runStage('compact-art',$art);
    agentCheck(($view['card']['plan']['node_count']??-1)===0 && ($view['card']['plan']['artifact_count']??0)===4,'art planning is reviewable but creates no canvas text nodes');
    $result=Workflow::confirmStagePlan($tenant,$user,$canvas,$thread,(int)$view['workflow']['state_revision']);
    [$nodes,$edges]=$graph($canvas);
    agentCheck(count($nodes)===2 && !isset($result['canvas_actions']) && count($result['workflow']['artifact_memory']??[])>=4,'confirmed art remains durable in workflow memory without graph mutation');

    $assets='<canvas-actions>'.json_encode(['nodes'=>[
        ['type'=>'image','artifact'=>'subject','title'=>'林夏主体图','prompt'=>'林夏，电影写实，正面半身，纯净背景','key'=>'subject','reference_keys'=>['art:character']],
        ['type'=>'image','artifact'=>'three_view','title'=>'林夏三视图','prompt'=>'林夏正侧背三视图','key'=>'views','depends_on'=>['subject'],'reference_keys'=>['art:views']],
    ]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).'</canvas-actions>';
    $view=$runStage('compact-assets',$assets,[$storyId]);
    agentCheck(str_contains((string)($provider->request['system_prompt']??''),ShortDramaPromptCatalog::defaults()['subject.character']),
        'Agent asset creation reads the original short-drama creative prompt source');
    agentCheck(($view['card']['type']??'')==='confirmation','auto image plan is held for owner confirmation');
    Workflow::confirmPlan($tenant,$user,$canvas,$thread,(int)$view['workflow']['state_revision'],(string)$view['workflow']['plan_hash']);
    [$nodes,$edges]=$graph($canvas);
    $subject=$nodes[2];$threeView=$nodes[3];
    agentCheck((int)($subject['metadata']['workflow_prompt_run_id']??0)>0
        && (int)($threeView['metadata']['workflow_prompt_run_id']??0)>0,
        'new Agent media nodes bind the frozen prompt run without exposing its contents');
    $inputs=static function (string $id) use (&$edges): array { return array_values(array_filter($edges,static fn(array $edge): bool=>(string)($edge['to']??'')===$id)); };
    agentCheck(count($nodes)===4 && str_contains((string)$subject['metadata']['prompt'],'米色风衣') && str_contains((string)$threeView['metadata']['prompt'],'录音笔一致'),'only chosen confirmed art text is materialized into quoted media prompts');
    agentCheck(count($inputs((string)$subject['id']))===0 && count($inputs((string)$threeView['id']))===1 && (string)$inputs((string)$threeView['id'])[0]['from']===(string)$subject['id'],'subject has no broad selected-text link; three-view depends only on its own main image');
    $checkNodes=$nodes;$checkNodes[2]['metadata']['status']='success';$checkNodes[2]['metadata']['asset_id']=991;
    $dependency=Canvas::agentAutoDependencyState($checkNodes,$edges,(string)$threeView['id']);
    agentCheck(($dependency['state']??'')==='ready' && ($dependency['references'][0]['asset_id']??0)===991,'three-view submits the completed subject image as a real generation reference');

    $boards='<canvas-actions>'.json_encode(['nodes'=>[
        ['type'=>'image','artifact'=>'scene','title'=>'雨夜办公室场景图','prompt'=>'雨夜办公室，不出现人物','key'=>'scene_1','reference_keys'=>['art:scene']],
        ['type'=>'image','artifact'=>'storyboard','title'=>'镜头一分镜图','prompt'=>'林夏在雨夜办公室拿起录音笔','key'=>'board_1','depends_on'=>['scene_1'],'reference_keys'=>['assets:subject']],
    ]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).'</canvas-actions>';
    $view=$runStage('compact-boards',$boards);
    $originalNodesJson=(string)Db::name(GraphService::TABLE)->where('id',$canvas)->value('nodes_json');
    $changedNodes=json_decode($originalNodesJson,true,512,JSON_THROW_ON_ERROR);
    $changedNodes[2]['metadata']['asset_id']=998;
    Db::name(GraphService::TABLE)->where('id',$canvas)->update(['nodes_json'=>json_encode($changedNodes,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);
    try { Workflow::confirmPlan($tenant,$user,$canvas,$thread,(int)$view['workflow']['state_revision'],(string)$view['workflow']['plan_hash']);throw new RuntimeException('changed subject image accepted under old quote'); }
    catch (RuntimeException $error) { agentCheck($error->getMessage()==='WORKFLOW_PLAN_SOURCE_CHANGED','replaced referenced subject image invalidates the frozen storyboard quote'); }
    Db::name(GraphService::TABLE)->where('id',$canvas)->update(['nodes_json'=>$originalNodesJson]);
    Workflow::confirmPlan($tenant,$user,$canvas,$thread,(int)$view['workflow']['state_revision'],(string)$view['workflow']['plan_hash']);
    [$nodes,$edges]=$graph($canvas);
    $board=$nodes[5];$boardInputs=$inputs((string)$board['id']);
    $boardSources=array_map(static fn(array $edge): string=>(string)$edge['from'],$boardInputs);
    sort($boardSources);$expected=[(string)$subject['id'],(string)$nodes[4]['id']];sort($expected);
    agentCheck(count($nodes)===6 && $boardSources===$expected,'storyboard links exactly its scene and relevant subject, not all earlier nodes');

    $videoPlanning=$reply([['type'=>'text','artifact'=>'video_prompt_plan','title'=>'镜头一视频规划','prompt'=>'镜号1；首帧：林夏持录音笔；尾帧：手机亮起；运镜：缓慢推进。','key'=>'video_1']],'已规划对应镜头视频');
    $view=$runStage('compact-video-plan',$videoPlanning);
    Workflow::confirmStagePlan($tenant,$user,$canvas,$thread,(int)$view['workflow']['state_revision']);
    [$nodes,$edges]=$graph($canvas);
    agentCheck(count($nodes)===6,'video prompt planning stays in dialogue, not a canvas text node');
    $video='<canvas-actions>'.json_encode(['nodes'=>[['type'=>'video','artifact'=>'storyboard_video','title'=>'镜头一视频','prompt'=>'林夏播放录音','key'=>'video_1','reference_keys'=>['storyboard:board_1','video_plan:video_1']]]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).'</canvas-actions>';
    $runStage('compact-video-node',$video);
    [$nodes,$edges]=$graph($canvas);
    $videoNode=$nodes[6];$videoInputs=$inputs((string)$videoNode['id']);
    agentCheck((int)($videoNode['metadata']['workflow_prompt_run_id']??0)>0,
        'video node binds the same frozen original prompt configuration');
    $videoSources=array_map(static fn(array $edge): string=>(string)$edge['from'],$videoInputs);
    agentCheck($videoSources===[(string)$board['id'],(string)$threeView['id'],(string)$nodes[4]['id']]
        && !empty($videoNode['metadata']['agent_manual_submit']) && empty($videoNode['metadata']['agent_auto_submit']),
        'video links its own storyboard, preferred subject turnaround and bound scene without auto-submitting');
    $readyNodes=$nodes;
    foreach ([$board['id']=>997,$threeView['id']=>996,$nodes[4]['id']=>995] as $sourceId=>$assetId) {
        foreach ($readyNodes as &$readyNode) if ((string)$readyNode['id']===(string)$sourceId) {
            $readyNode['metadata']['status']='success';$readyNode['metadata']['asset_id']=$assetId;
        }
        unset($readyNode);
    }
    $videoDependency=Canvas::agentAutoDependencyState($readyNodes,$edges,(string)$videoNode['id']);
    agentCheck(($videoDependency['state']??'')==='ready' && array_column($videoDependency['references'],'asset_id')===[997,996,995],
        'video quote and submission resolve the exact three linked images as real input assets');
    $legacyEdges=array_values(array_filter($edges,static fn(array $edge): bool=>(string)($edge['to']??'')!==(string)$videoNode['id'] || (string)($edge['from']??'')===(string)$board['id']));
    Db::name(GraphService::TABLE)->where('id',$canvas)->update(['edges_json'=>json_encode($legacyEdges,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);
    $repair=GraphService::repairAgentVideoReferences($tenant,$user,$canvas);
    agentCheck($repair['changed'] && $repair['nodes']===1 && !GraphService::repairAgentVideoReferences($tenant,$user,$canvas)['changed'],
        'idle legacy Agent video references are repaired once and repair is idempotent');
    try { ActionPlan::parse('<canvas-actions>{"nodes":[{"type":"image","artifact":"prop","title":"多余道具图","prompt":"道具","key":"prop"}]}</canvas-actions>','storyboard',true);throw new RuntimeException('extra prop canvas node accepted'); }
    catch (RuntimeException $error) { agentCheck($error->getMessage()==='INVALID_AGENT_ACTION','compact storyboard rejects unrequested prop image nodes'); }
    try { ActionPlan::parse('<canvas-actions>{"nodes":[{"type":"video","artifact":"storyboard_video","title":"孤立视频","prompt":"镜头","key":"video"}]}</canvas-actions>','video_nodes',true);throw new RuntimeException('video without storyboard accepted'); }
    catch (RuntimeException $error) { agentCheck($error->getMessage()==='INVALID_AGENT_ACTION','video proposal requires a matching storyboard reference before submission'); }
} finally {
    if ($canvas) {
        foreach (['outbox','event','message','run','thread'] as $kind) Db::name(Store::PREFIX.$kind)->where(['canvas_id'=>$canvas,'tenant_id'=>$tenant,'user_id'=>$user])->delete();
        Db::name(GraphService::TABLE)->where(['id'=>$canvas,'tenant_id'=>$tenant,'user_id'=>$user])->delete();
    }
    if ($config) Db::name('aigc_short_drama_config')->where(['id'=>$config,'tenant_id'=>$tenant])->delete();
}
echo "PASS compact canvas projection and targeted generation edges\n";
echo "NOT_RUN paid Provider, browser UI and video submission\n";
