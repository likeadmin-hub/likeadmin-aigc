<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';

use app\common\service\app\aigc_short_drama\ShortDramaCanvasService as Canvas;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationProviderInterface;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationStore as Store;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationWorker as Worker;
use think\facade\Db;

final class AgentGenerationModeProvider implements ConversationProviderInterface
{
    public function preflight(int $tenant,int $user,array $request): void {}
    public function generate(int $tenant,int $user,array $request): array
    {
        $prompt=(string)($request['messages'][count($request['messages'])-1]['content']??'');
        if (str_contains($prompt,'坏计划')) return ['content'=>'说明<canvas-actions>{bad}</canvas-actions>','tool_calls'=>[]];
        return ['content'=>'已准备好画布内容。<canvas-actions>{"nodes":[{"type":"text","title":"剧情梗概","prompt":"雨夜重逢的三幕剧情梗概","key":"story"},{"type":"image","title":"雨夜街景","prompt":"电影感雨夜街景，霓虹灯","key":"storyboard"},{"type":"video","title":"分镜视频 01","prompt":"雨夜街景中的角色走向镜头","key":"video_01","depends_on":["storyboard"]}]}</canvas-actions>','tool_calls'=>[]];
    }
}

if (Db::name('aigc_short_drama_config')->where('tenant_id',91031)->count()) throw new RuntimeException('Existing fixture config');
$config=0;$canvas=0;
try {
    $config=Db::name('aigc_short_drama_config')->insertGetId(['tenant_id'=>91031,'config_json'=>'{"canvas_agent":{"enabled":true}}','status'=>1]);
    $canvas=Canvas::create(91031,92031,['title'=>'P4 mode fixture'])['id'];
    Db::name('aigc_short_drama_canvas')->where('id',$canvas)->update(['nodes_json'=>json_encode([['id'=>1,'type'=>'text','title'=>'素材文本','x'=>0,'y'=>0,'width'=>320,'height'=>280,'metadata'=>['content'=>'参考素材','prompt'=>'','content_revision'=>1,'layout_revision'=>1]]])]);
    foreach (['manual','auto'] as $mode) {
        $thread=Store::create(91031,92031,$canvas,'mode-'.$mode)['id'];
        $ack=Store::enqueue(91031,92031,$canvas,$thread,['request_key'=>'request-'.$mode,'content'=>'请生成两项画布内容','selected_node_ids'=>['1'],'base_revision'=>(int)Db::name('aigc_short_drama_canvas')->where('id',$canvas)->value('graph_revision')],[
            'settings'=>['generation_mode'=>$mode,'reasoning_model'=>['id'=>'text-model'],'image_model'=>['id'=>'image-model'],'video_model'=>['id'=>'video-model']], 'skill'=>[],
        ]);
        agentCheck(Worker::process(91031,92031,$ack['run_id'],new AgentGenerationModeProvider())==='success',$mode.' action reply completes once');
        $message=Store::messages(91031,92031,$canvas,$thread)[1];
        agentCheck(($message['canvas_actions']['mode']??'')===$mode,$mode.' action projection preserves frozen generation mode');
        agentCheck(count($message['canvas_actions']['nodes']??[])===3,$mode.' action projects three opaque created node IDs');
        $nodes=json_decode((string)Db::name('aigc_short_drama_canvas')->where('id',$canvas)->value('nodes_json'),true);
        $created=array_slice($nodes,-3);
        agentCheck($created[0]['metadata']['model_code']==='text-model' && $created[1]['metadata']['channel']==='image-model',$mode.' server snapshot supplies configured models');
        agentCheck((bool)($created[0]['metadata']['agent_auto_submit']??false)===($mode==='auto'),$mode.' only auto mode marks browser submission intent');
        agentCheck((bool)($created[1]['metadata']['agent_auto_submit']??false)===($mode==='auto'),$mode.' auto mode keeps image submission intent');
        agentCheck(empty($created[2]['metadata']['agent_auto_submit']) && !empty($created[2]['metadata']['agent_manual_submit']),$mode.' storyboard video always requires an explicit node submission');
        $edges=json_decode((string)Db::name('aigc_short_drama_canvas')->where('id',$canvas)->value('edges_json'),true);
        agentCheck(count(array_filter($edges,static fn(array $edge): bool => (string)$edge['from']==='1'))>=2,$mode.' selected source is connected to created nodes');
        agentCheck(count(array_filter($edges,static fn(array $edge): bool => (string)$edge['from']===(string)$created[1]['id'] && (string)$edge['to']===(string)$created[2]['id']))===1,$mode.' storyboard video is connected to its storyboard image dependency');
    }
    $thread=Store::create(91031,92031,$canvas,'bad-plan')['id'];
    $ack=Store::enqueue(91031,92031,$canvas,$thread,['request_key'=>'bad-plan','content'=>'坏计划','base_revision'=>(int)Db::name('aigc_short_drama_canvas')->where('id',$canvas)->value('graph_revision')],[
        'settings'=>['generation_mode'=>'auto','reasoning_model'=>['id'=>'text-model'],'image_model'=>['id'=>'image-model'],'video_model'=>['id'=>'video-model']], 'skill'=>[],
    ]);
    agentCheck(Worker::process(91031,92031,$ack['run_id'],new AgentGenerationModeProvider())==='failed','malformed action is rejected without a graph mutation');
    agentCheck((string)Db::name(Store::PREFIX.'run')->where('id',$ack['run_id'])->value('error_code')==='UNSUPPORTED_MODEL_RESPONSE','malformed action is a known bounded model-output failure');
} finally {
    if ($canvas) {
        foreach (['outbox','event','message','run','thread'] as $kind) Db::name(Store::PREFIX.$kind)->where(['canvas_id'=>$canvas,'tenant_id'=>91031,'user_id'=>92031])->delete();
        Db::name('aigc_short_drama_canvas')->where(['id'=>$canvas,'tenant_id'=>91031,'user_id'=>92031])->delete();
    }
    if ($config) Db::name('aigc_short_drama_config')->where(['id'=>$config,'tenant_id'=>91031])->delete();
}
echo "NOT_RUN browser automatic submit, video quote confirmation and real Provider/billing\n";
