<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';

use app\common\service\app\aigc_short_drama\ShortDramaCanvasService as Canvas;
use app\common\service\app\aigc_short_drama\canvas_agent\AutoGenerationDispatcher;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationProviderInterface;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationStore as Store;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationWorker as Worker;
use think\facade\Db;

final class AutoRecoveryPlanProvider implements ConversationProviderInterface
{
    public function preflight(int $tenant,int $user,array $request): void {}
    public function generate(int $tenant,int $user,array $request): array
    {
        return ['content'=>'已创建。<canvas-actions>{"nodes":[{"type":"text","title":"梗概","prompt":"三幕梗概"},{"type":"image","title":"海报","prompt":"雨夜海报"},{"type":"video","title":"预告","prompt":"雨夜预告"}]}</canvas-actions>','tool_calls'=>[]];
    }
}

if (Db::name('aigc_short_drama_config')->where('tenant_id',91032)->count()) throw new RuntimeException('Existing fixture config');
$config=0;$canvas=0;
try {
    $config=Db::name('aigc_short_drama_config')->insertGetId(['tenant_id'=>91032,'config_json'=>'{"canvas_agent":{"enabled":true}}','status'=>1]);
    $canvas=Canvas::create(91032,92032,['title'=>'P4 durable auto fixture'])['id'];
    $thread=Store::create(91032,92032,$canvas,'auto-recovery')['id'];
    $ack=Store::enqueue(91032,92032,$canvas,$thread,['request_key'=>'auto-recovery','content'=>'创建素材','base_revision'=>0],[
        'settings'=>['generation_mode'=>'auto','reasoning_model'=>['id'=>'text-model'],'image_model'=>['id'=>'image-model'],'video_model'=>['id'=>'video-model']], 'skill'=>[],
    ]);
    agentCheck(Worker::process(91032,92032,$ack['run_id'],new AutoRecoveryPlanProvider())==='success','auto plan completion persists before browser activity');
    $document=Db::name('aigc_short_drama_canvas')->where('id',$canvas)->find();
    $nodes=json_decode((string)$document['nodes_json'],true);
    agentCheck(count($nodes)===3,'auto plan writes all proposed nodes');
    foreach ($nodes as $node) {
        $meta=(array)$node['metadata'];
        agentCheck(($meta['agent_auto_run_id']??0)===$ack['run_id'],'server owns auto node origin run');
        agentCheck(($meta['agent_auto_request_key']??'')==='agent.'.$ack['run_id'].'.'.$node['id'],'server allocates stable auto request key');
    }
    $eligible=AutoGenerationDispatcher::eligibleNodes($document);
    agentCheck(count($eligible)===2 && array_column($eligible,'id')===array_map(static fn(array $node): string => (string)$node['id'],array_slice($nodes,0,2)),'browser-independent dispatcher admits only text and image');
    $nodes[1]['metadata']['status']='queued';
    $document['nodes_json']=json_encode($nodes);
    agentCheck(count(AutoGenerationDispatcher::eligibleNodes($document))===1,'existing queued intent is never submitted again after worker restart');
    $nodes[0]['metadata']['status']='success';
    $document['nodes_json']=json_encode($nodes);
    agentCheck(AutoGenerationDispatcher::eligibleNodes($document)===[],'terminal and video nodes remain outside automatic dispatcher');
} finally {
    if ($canvas) {
        foreach (['outbox','event','message','run','thread'] as $kind) Db::name(Store::PREFIX.$kind)->where(['canvas_id'=>$canvas,'tenant_id'=>91032,'user_id'=>92032])->delete();
        Db::name('aigc_short_drama_canvas')->where(['id'=>$canvas,'tenant_id'=>91032,'user_id'=>92032])->delete();
    }
    if ($config) Db::name('aigc_short_drama_config')->where(['id'=>$config,'tenant_id'=>91032])->delete();
}
echo "NOT_RUN provider submission, browser rendering, video quote confirmation and billing settlement\n";
