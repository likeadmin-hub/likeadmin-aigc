<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';

use app\common\service\app\aigc_short_drama\ShortDramaCanvasService as Canvas;
use app\common\service\app\aigc_short_drama\canvas_agent\AutoGenerationDispatcher;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationActionPlan;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationProviderInterface;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationStore as Store;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationWorker as Worker;
use app\common\service\app\aigc_short_drama\canvas_agent\GraphService;
use think\facade\Db;

final class DependencyPlanProvider implements ConversationProviderInterface
{
    public function preflight(int $tenant,int $user,array $request): void {}
    public function generate(int $tenant,int $user,array $request): array
    {
        return ['content'=>'已规划三步。<canvas-actions>{"nodes":[{"type":"image","title":"角色设定","prompt":"电影角色设定图","key":"character"},{"type":"image","title":"角色海报","prompt":"引用角色设定的电影海报","key":"poster","depends_on":["character"]},{"type":"image","title":"独立场景","prompt":"雨夜独立场景","key":"scene"}]}</canvas-actions>','tool_calls'=>[]];
    }
}

if (Db::name('aigc_short_drama_config')->where('tenant_id',91034)->count()) throw new RuntimeException('Existing fixture config');
$config=0;$canvas=0;
try {
    $config=Db::name('aigc_short_drama_config')->insertGetId(['tenant_id'=>91034,'config_json'=>'{"canvas_agent":{"enabled":true}}','status'=>1]);
    $canvas=Canvas::create(91034,92034,['title'=>'P4 dependency scheduler fixture'])['id'];
    $thread=Store::create(91034,92034,$canvas,'dependency-scheduler')['id'];
    $ack=Store::enqueue(91034,92034,$canvas,$thread,['request_key'=>'dependency-scheduler','content'=>'创建角色、角色海报和独立场景','base_revision'=>0],[
        'settings'=>['generation_mode'=>'auto','reasoning_model'=>['id'=>'text-model'],'image_model'=>['id'=>'image-model'],'video_model'=>['id'=>'video-model']], 'skill'=>[],
    ]);
    agentCheck(Worker::process(91034,92034,$ack['run_id'],new DependencyPlanProvider())==='success','dependency action plan persists atomically');
    $document=Db::name('aigc_short_drama_canvas')->where('id',$canvas)->find();
    $nodes=json_decode((string)$document['nodes_json'],true);
    $edges=json_decode((string)$document['edges_json'],true);
    agentCheck(count($nodes)===3,'dependency plan creates all nodes');
    $dependency=array_values(array_filter($edges,static fn(array $edge): bool => ($edge['role']??'')==='agent_dependency'));
    agentCheck(count($dependency)===1 && (string)$dependency[0]['from']===(string)$nodes[0]['id'] && (string)$dependency[0]['to']===(string)$nodes[1]['id'],'only declared prior-step dependency becomes a graph edge');
    agentCheck(array_column(AutoGenerationDispatcher::eligibleNodes($document),'id')===[(string)$nodes[0]['id'],(string)$nodes[2]['id']],'dependent node waits while independent nodes remain eligible');
    $runsBefore=Db::name('aigc_short_drama_canvas_run')->where(['tenant_id'=>91034,'user_id'=>92034,'canvas_id'=>$canvas])->count();
    try {
        Canvas::submitIdempotent(91034,92034,['canvas_id'=>$canvas,'node_id'=>(string)$nodes[1]['id'],'type'=>'image','prompt'=>'不应在前序完成前调用','channel'=>'image-model','request_key'=>'manual-dependency-probe']);
        throw new RuntimeException('manual dependency bypassed');
    } catch (\Exception $error) {
        agentCheck($error->getMessage()==='前序节点尚未生成完成，请稍后再试','manual submit uses the same dependency gate before intent or Provider');
    }
    agentCheck(Db::name('aigc_short_drama_canvas_run')->where(['tenant_id'=>91034,'user_id'=>92034,'canvas_id'=>$canvas])->count()===$runsBefore,'manual dependency gate creates no run before its prerequisite');
    $nodes[0]['metadata']['status']='running';
    $document['nodes_json']=json_encode($nodes);
    agentCheck(array_column(AutoGenerationDispatcher::eligibleNodes($document),'id')===[(string)$nodes[2]['id']],'running prerequisite blocks only its child');
    $nodes[0]['metadata']['status']='failed';
    $document['nodes_json']=json_encode($nodes);
    agentCheck(array_column(AutoGenerationDispatcher::blockedNodes($document),'id')===[(string)$nodes[1]['id']],'failed prerequisite is terminally blocked rather than indefinitely waiting');
    agentCheck(array_column(AutoGenerationDispatcher::eligibleNodes($document),'id')===[(string)$nodes[2]['id']],'unrelated sibling remains schedulable after prerequisite failure');
    Db::name('aigc_short_drama_canvas')->where('id',$canvas)->update(['nodes_json'=>$document['nodes_json']]);
    agentCheck(GraphService::blockAgentDependentNode(91034,92034,$canvas,(string)$nodes[1]['id'],'前序节点生成失败，未提交此依赖节点'),'block transition is durable and scoped to the dependent node');
    $stored=json_decode((string)Db::name('aigc_short_drama_canvas')->where('id',$canvas)->value('nodes_json'),true);
    agentCheck(($stored[1]['metadata']['status']??'')==='failed' && ($stored[1]['metadata']['error']??'')==='前序节点生成失败，未提交此依赖节点','dependency failure is visible without a Provider call');
    foreach ([
        '<canvas-actions>{"nodes":[{"type":"image","title":"后置","prompt":"x","key":"later","depends_on":["missing"]}]}</canvas-actions>',
        '<canvas-actions>{"nodes":[{"type":"image","title":"一","prompt":"x","key":"same"},{"type":"image","title":"二","prompt":"x","key":"same"}]}</canvas-actions>',
    ] as $invalid) {
        try { ConversationActionPlan::parse($invalid); throw new RuntimeException('invalid action accepted'); }
        catch (RuntimeException $error) { agentCheck($error->getMessage()==='INVALID_AGENT_ACTION','unsafe dependency proposal is rejected before graph write'); }
    }
} finally {
    if ($canvas) {
        foreach (['outbox','event','message','run','thread'] as $kind) Db::name(Store::PREFIX.$kind)->where(['canvas_id'=>$canvas,'tenant_id'=>91034,'user_id'=>92034])->delete();
        Db::name('aigc_short_drama_canvas')->where(['id'=>$canvas,'tenant_id'=>91034,'user_id'=>92034])->delete();
    }
    if ($config) Db::name('aigc_short_drama_config')->where(['id'=>$config,'tenant_id'=>91034])->delete();
}
echo "NOT_RUN provider submission, real worker restart, browser rendering and billing settlement\n";
