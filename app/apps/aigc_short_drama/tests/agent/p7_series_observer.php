<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use think\facade\Db;
use app\common\service\app\aigc_short_drama\AigcShortDramaService as Production;
use app\common\service\app\aigc_short_drama\ShortDramaCanvasService as Canvas;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationStore as Store;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationWorkflow as Workflow;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationSeries as Series;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationExecution as Execution;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationWorker as Worker;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationProviderInterface;

final class SeriesNoChatProvider implements ConversationProviderInterface {
    public function preflight(int $tenant,int $user,array $request): void { throw new LogicException('Unexpected second provider path'); }
    public function generate(int $tenant,int $user,array $request): array { throw new LogicException('Unexpected second provider call'); }
}
$tenant=random_int(1500000000,1900000000);$user=$tenant;
Db::startTrans();
try {
    Db::name('aigc_short_drama_config')->insert(['tenant_id'=>$tenant,'config_json'=>'{"canvas_agent":{"enabled":true}}','status'=>1]);
    $canvas=Canvas::create($tenant,$user,['title'=>'Observer acceptance'])['id'];
    $thread=Store::create($tenant,$user,$canvas,'observer')['id'];
    $project=Db::name('aigc_short_drama_project')->insertGetId(['tenant_id'=>$tenant,'user_id'=>$user,'title'=>'Internal fixture']);
    $task='series-observer-'.$canvas;
    Db::name('aigc_short_drama_script_task')->insert(['tenant_id'=>$tenant,'user_id'=>$user,'task_id'=>$task,'project_id'=>$project,'status'=>'running','request_json'=>'{}','result_json'=>'{}']);
    $state=['workflow_snapshot'=>['series_core'=>true,'key'=>Workflow::KEY,'version'=>Series::VERSION,'stage_skill_versions'=>[]],
        'stage_state'=>['key'=>'story','status'=>'running'],'slot_values'=>['episode_count'=>'10集'],'creative_settings'=>[],
        'series'=>['episode_number'=>1,'tasks'=>['story'=>['task_id'=>$task,'project_id'=>$project,'run_id'=>0]]],
        'state_revision'=>1,'artifact_memory'=>[],'creative_brief'=>'寻找旧信','image_plan'=>[],'stage_plan'=>[],'plan_hash'=>'','plan_confirmation'=>['status'=>'not_required']];
    $ack=Store::enqueue($tenant,$user,$canvas,$thread,['request_key'=>'story','content'=>'开始设定','base_revision'=>0],
        ['settings'=>[],'skill'=>[],'workflow'=>$state,'thread_settings'=>['workflow_state'=>$state]]);
    $run=$ack['run_id'];
    agentCheck(Worker::process($tenant,$user,$run,new SeriesNoChatProvider())==='production_pending','pending core task defers the local observer');
    agentCheck(Production::projectLists($tenant,$user)['count']===0,'internal canvas production does not appear as another conventional project');
    agentCheck(Db::name('aigc_short_drama_script_task')->where('tenant_id',$tenant)->count()===1,'polling creates no duplicate production task');
    Db::name(Store::PREFIX.'outbox')->where('run_id',$run)->update(['available_at'=>0]);
    $claim=Execution::claim($tenant,$user,$run);
    Db::name(Store::PREFIX.'outbox')->where('run_id',$run)->update(['lease_until'=>time()-1]);
    agentCheck(Execution::expire($tenant,$user,$run),'expired local observer can be resumed safely');
    Db::name('aigc_short_drama_script_task')->where('task_id',$task)->update(['status'=>'success','result_json'=>json_encode(['title'=>'旧信','story_outline'=>'两人在旧宅找到一封信。'])]);
    agentCheck(Worker::process($tenant,$user,$run,new SeriesNoChatProvider())==='success','restart projects saved core result without a chat provider');
    $saved=Db::name(Store::PREFIX.'thread')->where('id',$thread)->find();
    $savedState=json_decode($saved['settings_json'],true)['workflow_state'];
    agentCheck($savedState['stage_state']['key']==='story' && $savedState['stage_state']['status']==='awaiting_stage_confirmation','story result waits for explicit user confirmation');
    agentCheck($savedState['series']['tasks']['story']['task_id']===$task,'stage projection retains durable core task binding');
    agentCheck(Worker::process($tenant,$user,$run,new SeriesNoChatProvider())==='not_claimed','completed observer is not replayed');
    agentCheck(count(Store::messages($tenant,$user,$canvas,$thread))===2,'one result message despite polls and restart');
    Workflow::confirmStagePlan($tenant,$user,$canvas,$thread,$savedState['state_revision']);
    $confirmed=json_decode(Db::name(Store::PREFIX.'thread')->where('id',$thread)->value('settings_json'),true)['workflow_state'];
    agentCheck($confirmed['stage_state']['key']==='outline' && $confirmed['stage_state']['status']==='ready','only confirmation advances story to outline');
} finally { Db::rollback(); }
