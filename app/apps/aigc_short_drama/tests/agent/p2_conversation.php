<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use think\facade\Db;
use app\common\service\app\aigc_short_drama\ShortDramaCanvasService as Canvas;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationStore as Store;
use app\common\service\app\aigc_short_drama\canvas_agent\GraphService as Graph;
function rejectsConversation(callable $action,string $code): void {
    try {$action();} catch (RuntimeException $error) {agentCheck($error->getMessage()===$code,$code);return;}
    throw new RuntimeException('Expected '.$code);
}
Db::startTrans();
try {
    $canvas=Canvas::create(91001,92001,['title'=>'P2 isolated conversation'])['id'];
    $other=Canvas::create(91001,92001,['title'=>'P2 other canvas'])['id'];
    rejectsConversation(fn()=>Store::create(91001,92001,$canvas,'create'),'CANVAS_AGENT_DISABLED');
    Db::name('aigc_short_drama_config')->insert(['tenant_id'=>91001,'config_json'=>'{"canvas_agent":{"enabled":true}}','status'=>1,'create_time'=>time(),'update_time'=>time()]);
    $thread=Store::create(91001,92001,$canvas,'create','对话');
    for ($i=0;$i<10;$i++) agentCheck(Store::create(91001,92001,$canvas,'create','对话')===$thread,'create replay '.$i);
    rejectsConversation(fn()=>Store::create(91001,92001,$canvas,'create','不同'),'IDEMPOTENCY_CONFLICT');
    rejectsConversation(fn()=>Store::threads(91002,92001,$canvas),'CANVAS_NOT_FOUND');
    rejectsConversation(fn()=>Store::threads(91001,92002,$canvas),'CANVAS_NOT_FOUND');
    rejectsConversation(fn()=>Store::messages(91001,92001,$other,$thread['id']),'THREAD_NOT_FOUND');
    Graph::patch(91001,92001,$canvas,['request_key'=>'add','expected_revision'=>0,'operations'=>[
        ['op'=>'add_node','node'=>['id'=>1,'type'=>'text','x'=>10,'y'=>20,'metadata'=>['content'=>'参考材料']]],
        // These image nodes intentionally overlap in the left position band.
        // A positional prompt must clarify instead of choosing either one.
        ['op'=>'add_node','node'=>['id'=>2,'type'=>'image','title'=>'左侧角色图','x'=>320,'y'=>20,'width'=>240,'height'=>180,'metadata'=>[]]],
        ['op'=>'add_node','node'=>['id'=>3,'type'=>'image','title'=>'左侧场景图','x'=>340,'y'=>240,'width'=>240,'height'=>180,'metadata'=>[]]],
    ]]);
    $before=Db::name(Graph::TABLE)->where('id',$canvas)->find();
    $request=['request_key'=>'message','content'=>'分析这段文字，不要生成节点','selected_node_ids'=>['1'],'base_revision'=>1];
    $snapshot=['settings'=>['reasoning_model'=>'isolated-model','image_model'=>'isolated-image','video_model'=>'isolated-video'],'skill'=>['id'=>7,'version'=>2,'content'=>'frozen skill']];
    $ack=Store::enqueue(91001,92001,$canvas,$thread['id'],$request,$snapshot);
    for ($i=0;$i<10;$i++) agentCheck(Store::enqueue(91001,92001,$canvas,$thread['id'],$request,$snapshot)===$ack,'send stable replay '.$i);
    foreach (['run','message','event','outbox'] as $kind) agentCheck(Db::name(Store::PREFIX.$kind)->where('canvas_id',$canvas)->count()===1,'atomic one '.$kind);
    agentCheck(Db::name(Graph::TABLE)->where('id',$canvas)->find()===$before,'send does not mutate graph or revision');
    agentCheck(Db::name('aigc_short_drama_canvas_run')->where('canvas_id',$canvas)->count()===0,'send creates no media generation run');
    $run=Db::name(Store::PREFIX.'run')->where('id',$ack['run_id'])->find();
    agentCheck(json_decode($run['settings_snapshot'],true)===$snapshot['settings'],'three selected model preferences frozen');
    agentCheck(json_decode($run['skill_snapshot'],true)===$snapshot['skill'],'skill version snapshot frozen');
    $context=json_decode($run['context_snapshot'],true);
    agentCheck($context['selected_nodes'][0]['id']==='1' && $context['selected_nodes'][0]['content']==='参考材料' && $context['material_trust']==='untrusted','references use ID with untrusted material');
    $messages=Store::messages(91001,92001,$canvas,$thread['id']);
    agentCheck(count($messages)===1 && $messages[0]['content']['text']===$request['content'],'reload reads persisted user message');
    agentCheck(Store::messages(91001,92001,$canvas,$thread['id'],1)===[],'message cursor does not replay old messages');
    $events=Store::events(91001,92001,$canvas,$thread['id']);
    agentCheck(count($events)===1 && $events[0]['cursor']===$ack['event_cursor'],'event cursor matches durable acknowledgement');
    agentCheck(Store::events(91001,92001,$canvas,$thread['id'],$ack['event_cursor'])===[],'event cursor does not replay old events');
    rejectsConversation(fn()=>Store::enqueue(91001,92001,$canvas,$thread['id'],array_replace($request,['content'=>'changed']),$snapshot),'IDEMPOTENCY_CONFLICT');
    rejectsConversation(fn()=>Store::enqueue(91001,92001,$canvas,$thread['id'],array_replace($request,['request_key'=>'second']),$snapshot),'THREAD_BUSY');
    rejectsConversation(fn()=>Store::enqueue(91001,92001,$canvas,$thread['id'],$request+['attachments'=>[['url'=>'https://invalid.test']]],$snapshot),'INVALID_ATTACHMENTS');
    $second=Store::create(91001,92001,$canvas,'another');
    rejectsConversation(fn()=>Store::enqueue(91001,92001,$canvas,$second['id'],$request,$snapshot),'IDEMPOTENCY_CONFLICT');
    $fresh=array_replace($request,['request_key'=>'fresh']);
    rejectsConversation(fn()=>Store::enqueue(91001,92001,$canvas,$second['id'],array_replace($fresh,['base_revision'=>0]),$snapshot),'VERSION_CONFLICT');
    rejectsConversation(fn()=>Store::enqueue(91001,92001,$canvas,$second['id'],array_replace($fresh,['selected_node_ids'=>['99']]),$snapshot),'NODE_NOT_FOUND');
    rejectsConversation(fn()=>Store::enqueue(91001,92001,$canvas,$second['id'],$fresh,['settings'=>[]]),'INVALID_RESOLVED_SNAPSHOT');
    foreach (['run','message','event','outbox'] as $kind) agentCheck(Db::name(Store::PREFIX.$kind)->where('canvas_id',$canvas)->count()===1,'failed acceptance leaves no partial '.$kind);
    // Inject failure at the LAST insertion boundary using a predicted outbox key.
    $next=(int)Db::query('SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',['la_'.Store::PREFIX.'run'])[0]['AUTO_INCREMENT'];
    Db::name(Store::PREFIX.'outbox')->insert(['tenant_id'=>91001,'user_id'=>92001,'canvas_id'=>$canvas,'run_id'=>0,'event_key'=>'run:'.$next,'available_at'=>time(),'create_time'=>time(),'update_time'=>time()]);
    $failed=false;
    try {Store::enqueue(91001,92001,$canvas,$second['id'],$fresh,$snapshot);} catch (Throwable $error) {$failed=str_contains($error->getMessage(),'Duplicate entry');}
    agentCheck($failed,'late outbox failure injected');
    foreach (['run','message','event'] as $kind) agentCheck(Db::name(Store::PREFIX.$kind)->where('canvas_id',$canvas)->count()===1,'late failure rolls back '.$kind);
    agentCheck((int)Db::name(Store::PREFIX.'thread')->where('id',$second['id'])->value('active_run_id')===0,'late failure keeps thread available');
    Db::name(Store::PREFIX.'outbox')->where(['canvas_id'=>$canvas,'run_id'=>0])->delete();
    Db::name(Graph::TABLE)->where('id',$canvas)->update(['graph_revision'=>2]);
    agentCheck(Store::enqueue(91001,92001,$canvas,$thread['id'],$request,['settings'=>[],'skill'=>[]])===$ack,'same request replays after graph and settings change');
    agentCheck(Db::name(Store::PREFIX.'run')->where('id',$ack['run_id'])->value('settings_snapshot')===$run['settings_snapshot'],'retry cannot replace frozen models');
    $clarifyThread=Store::create(91001,92001,$canvas,'clarify-thread','澄清引用');
    $clarifyRequest=['request_key'=>'clarify-left-image','content'=>'请分析左边那张图片','selected_node_ids'=>[],'base_revision'=>2];
    $outboxBefore=Db::name(Store::PREFIX.'outbox')->where('canvas_id',$canvas)->count();
    $clarified=Store::enqueue(91001,92001,$canvas,$clarifyThread['id'],$clarifyRequest,['settings'=>[],'skill'=>[]]);
    agentCheck($clarified['status']==='clarify','ambiguous positional image request becomes a clarification run');
    agentCheck(Db::name(Store::PREFIX.'outbox')->where('canvas_id',$canvas)->count()===$outboxBefore,'clarification creates no provider outbox event');
    $clarifyMessages=Store::messages(91001,92001,$canvas,$clarifyThread['id']);
    agentCheck(count($clarifyMessages)===2 && $clarifyMessages[1]['role']==='assistant','clarification persists a user request and assistant card');
    agentCheck(array_column($clarifyMessages[1]['reference_candidates'],'node_id')===['2','3'],'clarification returns only deterministic public image candidates');
    agentCheck(Store::enqueue(91001,92001,$canvas,$clarifyThread['id'],$clarifyRequest,['settings'=>[],'skill'=>[]])===$clarified,'clarification replay is idempotent');
    $explicitThread=Store::create(91001,92001,$canvas,'clarify-explicit','确认引用');
    $explicit=Store::enqueue(91001,92001,$canvas,$explicitThread['id'],array_replace($clarifyRequest,['request_key'=>'clarify-explicit-image','selected_node_ids'=>['2']]),$snapshot);
    agentCheck($explicit['status']==='queued','explicit candidate selection proceeds as a normal Agent request');
    Db::name('aigc_short_drama_config')->where('tenant_id',91001)->update(['config_json'=>'{}']);
    rejectsConversation(fn()=>Store::messages(91001,92001,$canvas,$thread['id']),'CANVAS_AGENT_DISABLED');
} finally {Db::rollback();}
echo "NOT_RUN API authentication, concurrent processes, model/Skill resolution, attachments, worker, Provider, frontend conversation\n";
