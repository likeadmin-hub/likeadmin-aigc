<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use think\facade\Db;
use app\common\service\app\aigc_short_drama\ShortDramaCanvasService as Canvas;
use app\common\service\app\aigc_short_drama\ShortDramaCanvasPages as Pages;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationStore as Store;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationWorkflow as Workflow;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationSeries as Series;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationAssetReuse as Reuse;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationImages as Images;
use app\common\service\app\aigc_short_drama\canvas_agent\GraphService as Graph;

// All owned fixtures roll back. No provider, media or billing request is made.
$tenant=random_int(1500000000,1900000000);$user=$tenant;
Db::startTrans();
try {
    Db::name('aigc_short_drama_config')->insert(['tenant_id'=>$tenant,'config_json'=>'{"canvas_agent":{"enabled":true,"workflow":{"enabled":true}}}','status'=>1]);
    $root=Canvas::create($tenant,$user,['title'=>'Series acceptance']);
    $manual=Canvas::create($tenant,$user,['canvas_project_id'=>$root['id'],'request_key'=>'manual:first']);
    $again=Canvas::create($tenant,$user,['canvas_project_id'=>$root['id'],'request_key'=>'manual:first']);
    agentCheck($manual['id']===$again['id'] && count($manual['pages'])===2,'manual page retry reuses one page');
    agentCheck($manual['canvas_project_id']===$root['id'] && Canvas::lists($tenant,$user)['count']===1,'pages remain in one listed project');
    agentCheck(Canvas::current($tenant,$user)['id']===$root['id'],'default project entry never chooses a child page');
    try { Pages::create($tenant+1,$user,$root['id'],'cross-tenant'); throw new LogicException('accepted cross-tenant page'); }
    catch (RuntimeException $e) { agentCheck($e->getMessage()==='CANVAS_NOT_FOUND','cross-tenant page creation rejected'); }
    $thread=Store::create($tenant,$user,$root['id'],'series-test')['id'];
    $state=['workflow_snapshot'=>['series_core'=>true,'key'=>Workflow::KEY,'version'=>Series::VERSION,'stage_skill_versions'=>[]],
        'stage_state'=>['key'=>'complete','status'=>'ready'],'slot_values'=>['episode_count'=>'10集'],'creative_settings'=>[],
        'series'=>['episode_number'=>1],'state_revision'=>1,'artifact_memory'=>[
            ['stage'=>'storyboard','artifact'=>'storyboard','node_id'=>'1'],['stage'=>'video_nodes','artifact'=>'storyboard_video','node_id'=>'2']],
        'image_plan'=>[],'stage_plan'=>[],'plan_hash'=>'','plan_confirmation'=>['status'=>'not_required']];
    Db::name(Store::PREFIX.'thread')->where('id',$thread)->update(['settings_json'=>json_encode(['workflow_state'=>$state])]);
    try { Workflow::answer($tenant,$user,$root['id'],$thread,1,'next_episode','继续'); throw new LogicException('accepted missing videos'); }
    catch (RuntimeException $e) { agentCheck($e->getMessage()==='WORKFLOW_EPISODE_MEDIA_INCOMPLETE','unfinished episode cannot create the next page'); }
    Db::name(Graph::TABLE)->where('id',$root['id'])->update(['nodes_json'=>json_encode([['id'=>'2','type'=>'video','metadata'=>['status'=>'success','video_url'=>'fixture.mp4','asset_id'=>1]]])]);
    $next=Workflow::answer($tenant,$user,$root['id'],$thread,1,'next_episode','继续');
    $repeat=Workflow::answer($tenant,$user,$root['id'],$thread,1,'next_episode','继续');
    agentCheck($next['next_page_id']===$repeat['next_page_id'] && $next['canvas_project_id']===$root['id'],'next episode is idempotent and stays in project');
    $page=Canvas::current($tenant,$user,$next['next_page_id']);
    $newThread=Db::name(Store::PREFIX.'thread')->where('canvas_id',$page['id'])->find();
    $newState=json_decode($newThread['settings_json'],true)['workflow_state'];
    agentCheck($page['nodes']===[] && $page['page_title']==='第 2 集' && $newState['stage_state']['key']==='episode' && Series::episode($newState)===2,'new page restores next episode state without previous graph');

    $proposal=['type'=>'image','artifact'=>'subject','key'=>'subject_a','title'=>'甲','prompt'=>'甲的固定设定'];
    $settings=['image_model'=>['id'=>'same-model']];
    $signature=Reuse::signatures([$proposal],$state,$settings)[0];
    $asset=Db::name('aigc_short_drama_asset')->insertGetId(['tenant_id'=>$tenant,'user_id'=>$user,'canvas_id'=>$root['id'],'task_id'=>'series-fixture-'.$root['id'],'uri'=>'uploads/fixture/character.png','status'=>'ready']);
    $node=['id'=>'3','type'=>'image','title'=>'甲','x'=>0,'y'=>0,'metadata'=>['prompt'=>$proposal['prompt'],'image'=>'uploads/fixture/character.png','asset_id'=>$asset,'status'=>'success','content_revision'=>1,'workflow_asset_signature'=>$signature,'workflow_artifact'=>'subject','workflow_key'=>'assets:subject_a']];
    Db::name(Graph::TABLE)->where('id',$root['id'])->update(['nodes_json'=>json_encode([$node])]);
    Db::name('aigc_short_drama_generation_task')->insert(['tenant_id'=>$tenant,'user_id'=>$user,'canvas_id'=>$root['id'],'task_id'=>'series-fixture-'.$root['id'],'status'=>'success','output_asset_ids'=>json_encode([$asset])]);
    $state['series']['asset_library']=[$signature=>['canvas_id'=>$root['id'],'node_id'=>'3','signature'=>$signature]];
    $doc=Db::name(Graph::TABLE)->where('id',$page['id'])->find();
    $reused=Reuse::find($tenant,$user,$doc,$state,[$proposal],$settings);
    agentCheck(count($reused)===1,'same subject and frozen model reuse prior page asset');
    $copy=Reuse::copyAsset($tenant,$user,$page['id'],$reused[0]);
    $copyRow=Db::name('aigc_short_drama_asset')->where('id',$copy)->find();
    agentCheck((int)$copyRow['canvas_id']===$page['id'] && Images::ownedGenerationReceipt($tenant,$user,$copyRow),'copied local asset retains verified original generation receipt');
    $copyRow['uri']='uploads/fixture/unowned.png';
    agentCheck(!Images::ownedGenerationReceipt($tenant,$user,$copyRow),'changed URI cannot borrow another file receipt');
    agentCheck(Reuse::find($tenant,$user,$doc,$state,[$proposal],['image_model'=>['id'=>'different-model']])===[],'different model does not reuse old appearance');
    $changed=$node;$changed['metadata']['ratio']='16:9';
    $sanitized=Graph::sanitizeManualNodes(['nodes_json'=>json_encode([$node])],[$changed]);
    agentCheck(empty($sanitized[0]['metadata']['workflow_asset_signature']),'editing generation parameters invalidates reuse signature');
    agentCheck(Db::name('aigc_short_drama_generation_task')->where('tenant_id',$tenant)->count()===1,'reuse creates no generation task');
    Canvas::delete($tenant,$user,$root['id']);
    agentCheck(Canvas::lists($tenant,$user)['count']===0,'project deletion hides all its pages');
    agentCheck(Db::name('aigc_short_drama_asset')->where('id',$asset)->value('delete_time')===0,'project deletion retains historical assets');
} finally { Db::rollback(); }
