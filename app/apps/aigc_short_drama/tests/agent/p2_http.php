<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use think\facade\Db;
use app\common\service\app\aigc_short_drama\ShortDramaCanvasService as Canvas;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationStore as Store;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationExecution as Execution;
function agentHttp(string $action,string $method='GET',array $body=[],string $token='isolated-agent-http',int $tenant=94001): array {
    $url='http://127.0.0.1:19082/api/app.aigc_short_drama.canvas_agent/'.$action.'?tenant_id='.$tenant;
    if ($method==='GET') $url.='&'.http_build_query($body);
    $context=stream_context_create(['http'=>['method'=>$method,'timeout'=>10,'ignore_errors'=>true,'header'=>"Content-Type: application/json\r\ntoken: ".$token."\r\n",'content'=>$method==='POST'?json_encode($body):'']]);
    $result=json_decode((string)file_get_contents($url,false,$context),true);
    if (!is_array($result)) throw new RuntimeException('Invalid isolated Agent HTTP response');
    return $result;
}
$inserted=[];$canvas=0;$process=null;
try {
    if (Db::name('tenant')->where('id',94001)->count() || Db::name('user')->whereIn('id',[95001,95002])->count() || Db::name('app')->where('code','aigc_short_drama')->count() || Db::name('aigc_short_drama_config')->where('tenant_id',94001)->count()) throw new RuntimeException('HTTP fixture scope is not empty');
    foreach ([
        ['tenant',['id'=>94001,'sn'=>'agent-http','name'=>'Isolated Agent HTTP','create_time'=>time(),'delete_time'=>null]],
        ['user',['id'=>95001,'sn'=>95001,'account'=>'agent-http','tenant_id'=>94001]],
        ['user',['id'=>95002,'sn'=>95002,'account'=>'agent-other','tenant_id'=>94001]],
        ['user_session',['tenant_id'=>94001,'user_id'=>95001,'token'=>'isolated-agent-http','terminal'=>4,'expire_time'=>time()+86400]],
        ['user_session',['tenant_id'=>94001,'user_id'=>95002,'token'=>'isolated-agent-other','terminal'=>4,'expire_time'=>time()+86400]],
        ['app',['code'=>'aigc_short_drama','status'=>'installed']],
        ['tenant_app',['tenant_id'=>94001,'app_code'=>'aigc_short_drama','buy_status'=>'paid','enable_status'=>'enabled','shelf_status'=>'on','expire_time'=>time()+3600]],
    ] as [$table,$row]) $inserted[]=[$table,Db::name($table)->insertGetId($row)];
    $canvas=Canvas::create(94001,95001,['title'=>'Agent HTTP fixture'])['id'];
    $process=proc_open([PHP_BINARY,'-S','127.0.0.1:19082','-t',app()->getRootPath().'public',__DIR__.'/p2_http_router.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start HTTP fixture');
    $ready=false;
    for ($i=0;$i<50;$i++) {$socket=@fsockopen('127.0.0.1',19082,$errno,$error,0.1);if ($socket) {fclose($socket);$ready=true;break;}usleep(100000);}
    if (!$ready) throw new RuntimeException('HTTP fixture not ready');
    agentCheck(agentHttp('threads','GET',['canvas_id'=>$canvas],'')['code']!==1,'Agent HTTP requires login');
    $disabled=agentHttp('createThread','POST',['canvas_id'=>$canvas,'request_key'=>'thread','agent_enabled'=>true]);
    agentCheck($disabled['code']!==1 && $disabled['msg']==='CANVAS_AGENT_DISABLED','request body cannot enable Agent');
    $config=Db::name('aigc_short_drama_config')->insertGetId(['tenant_id'=>94001,'config_json'=>'{"canvas_agent":{"enabled":true}}','status'=>1]);$inserted[]=['aigc_short_drama_config',$config];
    $product=Db::name('power_market_product')->insertGetId(['product_code'=>'agent-http-text','resource_type'=>'model','model_type'=>'text','name'=>'Isolated reasoning','source_code'=>'isolated-agent-test','upstream_resource_key'=>'agent-http-text','upstream_model_code'=>'isolated-text','upstream_channel_code'=>'isolated-channel','source_payload'=>'{}','status'=>1]);$inserted[]=['power_market_product',$product];
    $inserted[]=['power_market_sku',Db::name('power_market_sku')->insertGetId(['product_id'=>$product,'sku_key'=>'input','usage_unit'=>'token','sale_points'=>1,'status'=>1,'sale_status'=>1])];
    $create=['canvas_id'=>$canvas,'request_key'=>'thread','title'=>'新对话'];
    $created=agentHttp('createThread','POST',$create);
    if ($created['code']!==1) throw new RuntimeException('Create thread failed: '.json_encode($created));
    $thread=$created['data']['id'];
    agentCheck($thread>0 && agentHttp('createThread','POST',$create)['data']===$created['data'],'HTTP thread creation is idempotent');
    agentCheck(count(agentHttp('threads','GET',['canvas_id'=>$canvas])['data'])===1,'HTTP owned thread list');
    $args=['canvas_id'=>$canvas,'thread_id'=>$thread];
    agentCheck(agentHttp('messages','GET',$args,'isolated-agent-other')['code']!==1,'same tenant foreign user cannot read messages');
    agentCheck(agentHttp('messages','GET',$args,'isolated-agent-http',94002)['code']!==1,'foreign tenant cannot read messages');
    agentCheck(agentHttp('messages','GET',array_replace($args,['thread_id'=>'1e2']))['msg']==='INVALID_IDENTIFIER','HTTP identifiers reject numeric coercion');
    $send=$args+['request_key'=>'send','content'=>'请分析故事，不创建节点','base_revision'=>0,'preferences'=>['reasoning_model'=>(string)$product]];
    agentCheck(agentHttp('send','POST',$send+['user_id'=>95002])['msg']==='UNSUPPORTED_MESSAGE_FIELD','body cannot forge actor identity');
    $start=microtime(true);$response=agentHttp('send','POST',$send);
    if ($response['code']!==1) throw new RuntimeException('Send failed: '.json_encode($response));
    $ack=$response['data'];
    agentCheck($ack['status']==='queued' && microtime(true)-$start<5,'HTTP send returns queued without Provider execution');
    $stable=true;for ($i=0;$i<10;$i++) $stable=$stable && agentHttp('send','POST',$send)['data']===$ack;
    agentCheck($stable && Db::name(Store::PREFIX.'run')->where('canvas_id',$canvas)->count()===1,'ten HTTP sends create one logical run');
    agentCheck(agentHttp('send','POST',array_replace($send,['content'=>'different']))['msg']==='IDEMPOTENCY_CONFLICT','HTTP request key cannot change content');
    agentCheck(agentHttp('send','POST',array_replace($send,['request_key'=>'second']))['msg']==='THREAD_BUSY','HTTP active conversation rejects concurrent turn');
    $messages=agentHttp('messages','GET',$args)['data'];
    agentCheck(count($messages)===1 && $messages[0]['role']==='user','HTTP reload sees durable user message');
    agentCheck(agentHttp('events','GET',$args+['after'=>$ack['event_cursor']])['data']===[],'HTTP cursor skips delivered events');
    $claim=Execution::claim(94001,95001,$ack['run_id']);
    Execution::complete(94001,95001,$ack['run_id'],$claim['token'],$claim['fence'],'隔离测试回复');
    $messages=agentHttp('messages','GET',$args+['after'=>1])['data'];
    agentCheck(count($messages)===1 && $messages[0]['role']==='assistant','HTTP incremental messages read completed reply');
    agentCheck(array_column(agentHttp('events','GET',$args)['data'],'kind')===['run.queued','run.running','run.succeeded'],'HTTP events expose ordered lifecycle');
    $second=agentHttp('send','POST',array_replace($send,['request_key'=>'second']))['data'];
    $claim=Execution::claim(94001,95001,$second['run_id']);
    Execution::unknown(94001,95001,$second['run_id'],$claim['token'],$claim['fence']);
    Execution::complete(94001,95001,$second['run_id'],$claim['token'],$claim['fence'],'private late evidence');
    $events=agentHttp('events','GET',$args)['data'];
    agentCheck(!str_contains(json_encode($events),'private late evidence'),'public event endpoint excludes private late reply evidence');
    agentCheck(Db::name('aigc_short_drama_canvas_run')->where('canvas_id',$canvas)->count()===0,'HTTP conversation creates no media tasks');
    agentCheck((int)Db::name('aigc_short_drama_canvas')->where('id',$canvas)->value('graph_revision')===0,'HTTP conversation does not mutate graph');
    Db::name('tenant_app')->where(['tenant_id'=>94001,'app_code'=>'aigc_short_drama'])->update(['shelf_status'=>'off']);
    agentCheck(agentHttp('threads','GET',['canvas_id'=>$canvas])['code']!==1,'unshelved tenant app blocks conversation API');
    Db::name('tenant_app')->where(['tenant_id'=>94001,'app_code'=>'aigc_short_drama'])->update(['shelf_status'=>'on']);
    Db::name('aigc_short_drama_config')->where('id',$config)->update(['config_json'=>'{}']);
    agentCheck(agentHttp('messages','GET',$args)['msg']==='CANVAS_AGENT_DISABLED','turning Agent off blocks message entry again');
} finally {
    if (is_resource($process)) {proc_terminate($process);foreach ($pipes as $pipe) fclose($pipe);proc_close($process);}
    if ($canvas) {
        foreach (['outbox','event','message','run','thread'] as $kind) Db::name(Store::PREFIX.$kind)->where(['canvas_id'=>$canvas,'tenant_id'=>94001,'user_id'=>95001])->delete();
        Db::name('aigc_short_drama_canvas')->where(['id'=>$canvas,'tenant_id'=>94001,'user_id'=>95001])->delete();
    }
    foreach (array_reverse($inserted) as [$table,$id]) Db::name($table)->where('id',$id)->delete();
}
echo "NOT_RUN Provider, billing, production migration and frontend; completion uses isolated direct service fixture\n";
