<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use app\common\service\app\aigc_short_drama\ShortDramaCanvasService as Canvas;
use app\common\service\app\aigc_short_drama\ShortDramaPromptWorkspace;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationStore;
use think\facade\Db;

final class SharedSubmissionVideoProvider
{
    public static array $quoted=[];
    public static array $submitted=[];
    public static int $submitCalls=0;
    public static function estimate(int $tenant,array $payload): array
    {
        self::$quoted=$payload;
        return ['market_product_id'=>1,'market_sku_id'=>2,'tenant_cost_points'=>3,'user_charge_points'=>4,'usage_unit'=>'call','settlement_mode'=>'reserved'];
    }
    public static function generate(int $tenant,int $user,array $payload): array
    {
        ++self::$submitCalls;
        self::$submitted=$payload;
        return ['task_id'=>'shared-template-fixture','status'=>'running'];
    }
}
class_alias(SharedSubmissionVideoProvider::class,'app\\common\\service\\app\\aigc_video\\AigcVideoService');

$tenant=91077;$user=92077;
Db::startTrans();
try {
    Db::name('tenant')->insert(['id'=>$tenant,'sn'=>'shared-template-fixture','create_time'=>time(),'point_balance'=>100]);
    Db::name('user')->insert(['id'=>$user,'sn'=>$user,'account'=>'shared-template-fixture','tenant_id'=>$tenant,'user_money'=>100]);
    $templates=[
        'three_view_prompt_template'=>'三视图统一模板 {{subject_name}}：{{prompt}}',
        'shot_image_prompt_template'=>'分镜图统一模板 {{shot_title}}：{{prompt}}',
        'shot_video_prompt_template'=>'分镜视频统一模板 {{shot_title}} / {{duration}}秒：{{prompt}}',
    ];
    $config=['prompt_config'=>$templates];
    $frozen=ShortDramaPromptWorkspace::resolve($tenant,['mode'=>'legacy','legacy_config'=>$config],[],$config);
    foreach (['three_view'=>'三视图统一模板','storyboard'=>'分镜图统一模板','storyboard_video'=>'分镜视频统一模板'] as $artifact=>$prefix) {
        $rendered=AigcShortDramaService::canvasAgentSubmissionPrompt($tenant,$frozen,$artifact,'林夏保持一致',['subject_name'=>'林夏','shot_title'=>'镜头一','duration'=>'5']);
        agentCheck(str_contains($rendered,$prefix) && str_contains($rendered,'林夏保持一致'),'Agent '.$artifact.' uses the formal short-drama submit template');
    }
    try { AigcShortDramaService::canvasAgentSubmissionPrompt($tenant+1,$frozen,'storyboard_video','x'); throw new RuntimeException('cross-tenant prompt snapshot accepted'); }
    catch (Exception $error) { agentCheck($error->getMessage()==='PROMPT_SNAPSHOT_TENANT_MISMATCH','template snapshot rejects a different tenant'); }

    $canvas=(int)Canvas::create($tenant,$user,['title'=>'Shared prompt template'])['id'];
    $thread=(int)ConversationStore::create($tenant,$user,$canvas,'shared-template-thread')['id'];
    $run=Db::name(ConversationStore::PREFIX.'run')->insertGetId([
        'tenant_id'=>$tenant,'user_id'=>$user,'canvas_id'=>$canvas,'thread_id'=>$thread,
        'request_key'=>'shared-template-run','request_hash'=>str_repeat('a',64),'status'=>'success','version'=>1,
        'context_snapshot'=>json_encode(['workflow'=>['workflow_snapshot'=>['key'=>'short_drama_creation','version'=>'2026-09-23.8','creative_prompt_snapshot'=>$frozen]]],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
        'skill_snapshot'=>'{}','settings_snapshot'=>'{}','ack_json'=>'{}','error_code'=>'','create_time'=>time(),'update_time'=>time(),'delete_time'=>0,
    ]);
    $row=Db::name('aigc_short_drama_canvas')->where('id',$canvas)->find();
    $nodes=[['id'=>1,'type'=>'video','title'=>'镜头一','metadata'=>['prompt'=>'林夏缓慢走近镜头','content_revision'=>1,'workflow_artifact'=>'storyboard_video','workflow_source_stage'=>'video_nodes','workflow_prompt_run_id'=>$run]]];
    Db::name('aigc_short_drama_canvas')->where('id',$canvas)->update(['nodes_json'=>json_encode($nodes,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
    $request=['canvas_id'=>$canvas,'node_id'=>'1','type'=>'video','prompt'=>'林夏缓慢走近镜头','duration'=>5,'request_key'=>'shared-template-video'];
    $quote=Canvas::quote($tenant,$user,$request);
    $expected='分镜视频统一模板 镜头一 / 5秒：林夏缓慢走近镜头';
    agentCheck(SharedSubmissionVideoProvider::$quoted['prompt']===$expected,'quote prices the original configured template output');
    Canvas::confirmQuote($tenant,$user,['canvas_id'=>$canvas,'node_id'=>'1','quote_token'=>$quote['quote_token']]);
    $submitted=Canvas::submitIdempotent($tenant,$user,$request+['quote_token'=>$quote['quote_token']]);
    agentCheck(($submitted['status']??'')==='running' && SharedSubmissionVideoProvider::$submitted['prompt']===$expected,'confirmed submit sends the same templated prompt as quote');
    $replayed=Canvas::submitIdempotent($tenant,$user,$request);
    agentCheck(($replayed['id']??0)===($submitted['id']??-1) && SharedSubmissionVideoProvider::$submitCalls===1,'same request key replays without a second Provider call');
    $stale=$request;$stale['request_key']='shared-template-edited';$stale['prompt']='林夏转身离开';
    $editedQuote=Canvas::quote($tenant,$user,$stale);
    agentCheck(SharedSubmissionVideoProvider::$quoted['prompt']==='分镜视频统一模板 镜头一 / 5秒：林夏转身离开','manual prompt edit is wrapped once by the same frozen template');
    agentCheck($editedQuote['quote_token']!==$quote['quote_token'],'changed prompt receives a distinct quote');
    agentCheck((string)$row['nodes_json']==='[]','fixture starts with no preexisting Agent node');
} finally {
    Db::rollback();
}

echo "NOT_RUN paid Provider, real video generation and browser interaction; local existing database fixture rolled back\n";
