<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use think\facade\Db;
use app\common\service\app\aigc_short_drama\ShortDramaCanvasService as Canvas;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationExecution;
use app\common\service\app\aigc_short_drama\canvas_agent\GraphService as Graph;

// JSON-lines bridge to real HTTP inside the internal network. No host port,
// business credentials, arbitrary URLs, provider routes or user profile.
$inserted=[];$canvasId=0;$process=null;
$mockGeneration=($argv[1]??'')==='mock-generation';
$agentConversation=($argv[1]??'')==='agent-conversation';
try {
    if (Db::name('tenant')->where('id',94011)->count() || Db::name('user')->where('id',95011)->count() || Db::name('app')->whereIn('code',['aigc_short_drama','aigc_canvas'])->count()) throw new RuntimeException('Browser fixture scope is not empty');
    foreach ([
        ['tenant',['id'=>94011,'sn'=>'browser-fixture','name'=>'Isolated browser','create_time'=>time(),'delete_time'=>null]],
        ['user',['id'=>95011,'sn'=>95011,'account'=>'browser-fixture','tenant_id'=>94011]],
        ['user_session',['tenant_id'=>94011,'user_id'=>95011,'token'=>'isolated-browser-http','terminal'=>4,'expire_time'=>time()+3600]],
        ['app',['code'=>'aigc_short_drama','status'=>'installed']],
        ['app',['code'=>'aigc_canvas','status'=>'disabled']],
        ['tenant_app',['tenant_id'=>94011,'app_code'=>'aigc_canvas','buy_status'=>'paid','enable_status'=>'disabled','shelf_status'=>'on','expire_time'=>time()+3600]],
        ['tenant_app',['tenant_id'=>94011,'app_code'=>'aigc_short_drama','buy_status'=>'paid','enable_status'=>'enabled','shelf_status'=>'on','expire_time'=>time()+3600]],
        ['aigc_short_drama_config',['tenant_id'=>94011,'config_json'=>$agentConversation?'{"canvas_agent":{"enabled":true}}':'{"canvas_agent":{"enabled":false}}','status'=>1]],
    ] as [$table,$row]) $inserted[]=[$table,Db::name($table)->insertGetId($row)];
    if ($agentConversation) {
        // An isolated market record makes ConversationSettings exercise the
        // same catalog resolver as production.  The test never starts a
        // worker or calls this model/provider.
        $product=Db::name('power_market_product')->insertGetId([
            'product_code'=>'isolated-browser-agent-text','resource_type'=>'model','model_type'=>'text',
            'name'=>'Isolated browser reasoning','source_code'=>'isolated-agent-test',
            'upstream_resource_key'=>'isolated-browser-agent-text','upstream_model_code'=>'isolated-browser-reasoning',
            'upstream_channel_code'=>'isolated-browser-channel','source_payload'=>'{}','status'=>1,
        ]);
        $inserted[]=['power_market_product',$product];
        $sku=Db::name('power_market_sku')->insertGetId([
            'product_id'=>$product,'sku_key'=>'input','usage_unit'=>'token','sale_points'=>1,'status'=>1,'sale_status'=>1,
        ]);
        $inserted[]=['power_market_sku',$sku];
    }
    if ($mockGeneration) {
        Db::name('tenant')->where('id',94011)->update(['point_balance'=>100]);
        Db::name('user')->where('id',95011)->update(['user_money'=>100]);
    }
    $canvasId=Canvas::create(94011,95011,['title'=>'HTTP browser fixture'])['id'];
    $router=$mockGeneration?'/browser_generation_router.php':($agentConversation?'/browser_agent_router.php':'/http_router.php');
    $process=proc_open([PHP_BINARY,'-S','127.0.0.1:19080','-t',app()->getRootPath().'public',__DIR__.$router],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start isolated HTTP fixture');
    $ready=false;
    for ($i=0;$i<50;$i++) {
        $socket=@fsockopen('127.0.0.1',19080,$errno,$error,0.1);
        if ($socket) {fclose($socket);$ready=true;break;}
        usleep(100000);
    }
    if (!$ready) throw new RuntimeException('HTTP fixture not ready');
    echo json_encode(['ready'=>true,'canvas_id'=>$canvasId,'tenant_id'=>94011,'reasoning_model_id'=>(string)($product??'')]),PHP_EOL;
    while (($line=fgets(STDIN))!==false) {
        $request=json_decode($line,true,512,JSON_THROW_ON_ERROR);
        if (($request['action']??'')==='close') break;
        $action=$request['action']??'';$method=$request['method']??'GET';$body=$request['body']??[];
        if ($mockGeneration && $action==='evidence') {
            $scope=['tenant_id'=>94011,'user_id'=>95011];
            echo json_encode(['result'=>[
                'received'=>Db::name('aigc_short_drama_test_provider_receipt')->where($scope)->count(),
                'runs'=>Db::name('aigc_short_drama_canvas_run')->where($scope+['canvas_id'=>$canvasId])->count(),
                'tenant_balance'=>Db::name('tenant')->where('id',94011)->value('point_balance'),
                'user_balance'=>Db::name('user')->where('id',95011)->value('user_money'),
                'tenant_charges'=>Db::name('tenant_point_log')->where('tenant_id',94011)->count(),
                'user_charges'=>Db::name('user_account_log')->where('user_id',95011)->count(),
            ]]),PHP_EOL;
            continue;
        }
        if ($agentConversation && $action==='agentEvidence') {
            $scope=['tenant_id'=>94011,'user_id'=>95011,'canvas_id'=>$canvasId];
            echo json_encode(['result'=>[
                'threads'=>Db::name('aigc_short_drama_canvas_agent_thread')->where($scope)->count(),
                'messages'=>Db::name('aigc_short_drama_canvas_agent_message')->where($scope)->count(),
                'runs'=>Db::name('aigc_short_drama_canvas_agent_run')->where($scope)->count(),
                'outbox'=>Db::name('aigc_short_drama_canvas_agent_outbox')->where($scope)->count(),
                'media_runs'=>Db::name('aigc_short_drama_canvas_run')->where($scope)->count(),
                'tenant_charges'=>Db::name('tenant_point_log')->where('tenant_id',94011)->count(),
                'user_charges'=>Db::name('user_account_log')->where('user_id',95011)->count(),
            ]]),PHP_EOL;
            continue;
        }
        if ($agentConversation && $action==='agentLastSelectionEvidence') {
            $snapshot=Db::name('aigc_short_drama_canvas_agent_run')->where(['tenant_id'=>94011,'user_id'=>95011,'canvas_id'=>$canvasId])->order('id','desc')->value('context_snapshot');
            $context=json_decode((string)$snapshot,true,512,JSON_THROW_ON_ERROR);
            $selected=array_values((array)($context['selected_nodes']??[]));
            echo json_encode(['result'=>array_values(array_map(static fn(array $node): string => (string)$node['id'],$selected))]),PHP_EOL;
            continue;
        }
        if ($agentConversation && $action==='agentMoveSelectedImage') {
            $document=Db::name(Graph::TABLE)->where(['id'=>$canvasId,'tenant_id'=>94011,'user_id'=>95011,'delete_time'=>0])->find();
            $nodes=json_decode((string)($document['nodes_json']??'[]'),true,512,JSON_THROW_ON_ERROR);
            $node=current(array_filter($nodes,static fn(array $item): bool => (string)($item['id']??'')==='2'));
            if (!$document || !is_array($node)) throw new RuntimeException('Agent fixture image node missing');
            $result=Graph::patch(94011,95011,$canvasId,['request_key'=>'browser-move-selected-image','expected_revision'=>(int)$document['graph_revision'],'operations'=>[
                ['op'=>'move_nodes','nodes'=>[['id'=>2,'x'=>999,'expected_layout_revision'=>(int)($node['metadata']['layout_revision']??0)]]],
            ]]);
            $snapshot=Db::name('aigc_short_drama_canvas_agent_run')->where(['tenant_id'=>94011,'user_id'=>95011,'canvas_id'=>$canvasId])->order('id','desc')->value('context_snapshot');
            $context=json_decode((string)$snapshot,true,512,JSON_THROW_ON_ERROR);
            $frozen=(array)(($context['selected_nodes']??[])[0]??[]);
            echo json_encode(['result'=>['graph_x'=>(int)$result['nodes'][0]['x'],'frozen_id'=>(string)($frozen['id']??''),'frozen_x'=>(int)($frozen['x']??-1)]]),PHP_EOL;
            continue;
        }
        if ($agentConversation && $action==='agentPreferenceEvidence') {
            echo json_encode(['result'=>Db::name('aigc_short_drama_canvas_agent_preference')->where(['tenant_id'=>94011,'user_id'=>95011])->find() ?: null]),PHP_EOL;
            continue;
        }
        if ($agentConversation && $action==='agentAttachmentEvidence') {
            $run=Db::name('aigc_short_drama_canvas_agent_run')->where(['tenant_id'=>94011,'user_id'=>95011,'canvas_id'=>$canvasId])->order('id','desc')->find();
            $message=$run ? Db::name('aigc_short_drama_canvas_agent_message')->where(['run_id'=>$run['id'],'role'=>'user'])->find() : [];
            $snapshot=json_decode((string)($run['context_snapshot']??'{}'),true);
            echo json_encode(['result'=>['attachments'=>json_decode((string)($message['attachments_json']??'[]'),true),'image_count'=>count((array)($snapshot['attachment_images']??[]))]]),PHP_EOL;
            continue;
        }
        if ($agentConversation && $action==='agentTextWritebackEvidence') {
            $document=Db::name(Graph::TABLE)->where(['id'=>$canvasId,'tenant_id'=>94011,'user_id'=>95011,'delete_time'=>0])->find();
            $nodes=json_decode((string)($document['nodes_json']??'[]'),true,512,JSON_THROW_ON_ERROR);
            $node=current(array_filter($nodes,static fn(array $item): bool => (string)($item['id']??'')==='1'));
            $run=Db::name('aigc_short_drama_canvas_agent_run')->where(['tenant_id'=>94011,'user_id'=>95011,'canvas_id'=>$canvasId])->order('id')->find();
            $snapshot=json_decode((string)($run['context_snapshot']??'{}'),true,512,JSON_THROW_ON_ERROR);
            $original=(array)(($snapshot['selected_nodes']??[])[0]??[]);
            echo json_encode(['result'=>[
                'content'=>(string)($node['metadata']['content']??''),
                'content_revision'=>(int)($node['metadata']['content_revision']??0),
                'source_content'=>(string)($original['content']??''),
                'source_revision'=>(int)($original['content_revision']??0),
            ]]),PHP_EOL;
            continue;
        }
        if ($agentConversation && $action==='agentSeedTextNode') {
            $document=Db::name(Graph::TABLE)->where(['id'=>$canvasId,'tenant_id'=>94011,'user_id'=>95011,'delete_time'=>0])->find();
            if (!$document) throw new RuntimeException('Agent fixture canvas missing');
            $result=Graph::patch(94011,95011,$canvasId,['request_key'=>'browser-text-writeback','expected_revision'=>(int)$document['graph_revision'],'operations'=>[
                ['op'=>'add_node','node'=>['id'=>1,'type'=>'text','title'=>'原始文本节点','x'=>40,'y'=>20,'width'=>300,'height'=>180,'metadata'=>['content'=>'原始描述，需要精简成三句话。']]],
            ]]);
            echo json_encode(['result'=>$result]),PHP_EOL;
            continue;
        }
        if ($agentConversation && $action==='agentSeedAmbiguousImages') {
            // Add only owned, metadata-free image placeholders after the
            // normal conversation test. This exercises the real graph and
            // Agent ambiguity path without a file, provider or charge.
            $document=Db::name(Graph::TABLE)->where(['id'=>$canvasId,'tenant_id'=>94011,'user_id'=>95011,'delete_time'=>0])->find();
            if (!$document) throw new RuntimeException('Agent fixture canvas missing');
            $result=Graph::patch(94011,95011,$canvasId,['request_key'=>'browser-ambiguous-images','expected_revision'=>(int)$document['graph_revision'],'operations'=>[
                ['op'=>'add_node','node'=>['id'=>2,'type'=>'image','title'=>'左侧角色图','x'=>320,'y'=>20,'width'=>240,'height'=>180,'metadata'=>[]]],
                ['op'=>'add_node','node'=>['id'=>3,'type'=>'image','title'=>'左侧场景图','x'=>340,'y'=>240,'width'=>240,'height'=>180,'metadata'=>[]]],
            ]]);
            echo json_encode(['result'=>$result]),PHP_EOL;
            continue;
        }
        // Browser-only harness helpers drive the real durable completion and
        // SSE read paths without registering a Provider, scheduler or billable
        // model. They are unavailable outside this isolated JSON-lines bridge.
        if ($agentConversation && in_array($action,['agentComplete','agentStream'],true)) {
            $thread=(int)($body['thread_id']??0);$run=(int)($body['run_id']??0);
            $owned=$thread>0 && $run>0
                && Db::name('aigc_short_drama_canvas_agent_thread')->where(['id'=>$thread,'tenant_id'=>94011,'user_id'=>95011,'canvas_id'=>$canvasId])->count()===1
                && Db::name('aigc_short_drama_canvas_agent_run')->where(['id'=>$run,'thread_id'=>$thread,'tenant_id'=>94011,'user_id'=>95011,'canvas_id'=>$canvasId])->count()===1;
            if (!$owned) throw new RuntimeException('Agent helper outside isolated browser fixture');
            if ($action==='agentComplete') {
                $claim=ConversationExecution::claim(94011,95011,$run);
                if (!$claim) throw new RuntimeException('Agent helper cannot claim isolated run');
                $result=ConversationExecution::complete(94011,95011,$run,$claim['token'],$claim['fence'],(string)($body['content']??''));
                echo json_encode(['result'=>['completed'=>$result]]),PHP_EOL;
                continue;
            }
            if (trim((string)($body['content']??''))!=='') {
                $claim=ConversationExecution::claim(94011,95011,$run);
                if ($claim) {
                    ConversationExecution::complete(94011,95011,$run,$claim['token'],$claim['fence'],(string)$body['content']);
                } else {
                    // SSE reconnects read the already completed run; they must
                    // not claim it again or silently accept a different reply.
                    $status=Db::name('aigc_short_drama_canvas_agent_run')->where(['id'=>$run,'tenant_id'=>94011,'user_id'=>95011,'canvas_id'=>$canvasId])->value('status');
                    $reply=Db::name('aigc_short_drama_canvas_agent_message')->where(['run_id'=>$run,'tenant_id'=>94011,'user_id'=>95011,'canvas_id'=>$canvasId,'role'=>'assistant'])->value('content_json');
                    if ($status!=='success' || (json_decode((string)$reply,true)['text']??null)!==(string)$body['content']) {
                        throw new RuntimeException('Agent helper cannot replay isolated SSE run');
                    }
                }
            }
            $url='http://127.0.0.1:19080/api/app.aigc_short_drama.canvas_agent/stream?tenant_id=94011';
            $streamBody=['canvas_id'=>$canvasId,'thread_id'=>$thread,'run_id'=>$run,'event_after'=>0,'message_after'=>0,'wait_seconds'=>0];
            $context=stream_context_create(['http'=>['method'=>'POST','timeout'=>10,'ignore_errors'=>true,
                'header'=>"Content-Type: application/json\r\nAccept: text/event-stream\r\ntoken: isolated-browser-http\r\n",'content'=>json_encode($streamBody)]]);
            $stream=file_get_contents($url,false,$context);
            if (!is_string($stream)) throw new RuntimeException('Agent helper SSE response missing');
            echo json_encode(['result'=>$stream]),PHP_EOL;
            continue;
        }
        $allowed=in_array($action,['current','save'],true) && (int)($body['id']??0)===$canvasId;
        if ($agentConversation && $action==='patch') {
            $operations=$body['operations']??null;
            $allowed=(int)($body['canvas_id']??0)===$canvasId
                && is_array($operations) && count($operations)===1
                && is_array($operations[0])
                && array_keys($operations[0])===['op','node_id','run_id']
                && ($operations[0]['op']??null)==='apply_agent_text';
        }
        if ($agentConversation && $action==='assetRegister') $allowed=(int)($body['canvas_id']??0)===$canvasId;
        if (in_array($action,['assets','independentCanvas'],true)) $allowed=$method==='GET';
        if ($mockGeneration && $action==='run') $allowed=(int)($body['canvas_id']??0)===$canvasId;
        if ($mockGeneration && $action==='task') $allowed=Db::name('aigc_short_drama_canvas_run')->where(['id'=>(int)($body['id']??0),'canvas_id'=>$canvasId,'tenant_id'=>94011,'user_id'=>95011])->count()===1;
        $agentActions=['agentThreads'=>'threads','agentPreferences'=>'preferences','agentSavePreferences'=>'savePreferences','agentCreateThread'=>'createThread','agentMessages'=>'messages','agentEvents'=>'events','agentSend'=>'send','agentRun'=>'run','agentStop'=>'stop'];
        if ($agentConversation && isset($agentActions[$action])) {
            $allowed=(int)($body['canvas_id']??0)===$canvasId;
            foreach (['thread_id','run_id'] as $key) {
                if ($allowed && isset($body[$key])) {
                    $table=$key==='thread_id'?'aigc_short_drama_canvas_agent_thread':'aigc_short_drama_canvas_agent_run';
                    $allowed=Db::name($table)->where($scope=['id'=>(int)$body[$key],'tenant_id'=>94011,'user_id'=>95011,'canvas_id'=>$canvasId])->count()===1;
                }
            }
        }
        if (!$allowed || !in_array($method,['GET','POST'],true)) throw new RuntimeException('Request outside isolated browser fixture');
        $path=isset($agentActions[$action]) ? 'app.aigc_short_drama.canvas_agent/'.$agentActions[$action] : match ($action) {
            'assets'=>'app.aigc_short_drama.asset/lists',
            'assetRegister'=>'app.aigc_short_drama.asset/register',
            'independentCanvas'=>'app.aigc_canvas.project/lists',
            default=>'app.aigc_short_drama.canvas/'.$action,
        };
        // Read-only resource probes cannot invoke project material repair or
        // select a caller-controlled tenant/URL through query parameters.
        if (in_array($action,['assets','independentCanvas'],true)) $body=['page_size'=>100];
        $url='http://127.0.0.1:19080/api/'.$path.'?tenant_id=94011';
        if ($method==='GET') $url.='&'.http_build_query($body);
        $context=stream_context_create(['http'=>['method'=>$method,'timeout'=>10,'ignore_errors'=>true,
            'header'=>"Content-Type: application/json\r\ntoken: isolated-browser-http\r\n",
            'content'=>$method==='POST'?json_encode($body):'',
        ]]);
        $raw=file_get_contents($url,false,$context);
        if (!is_string($raw) || trim($raw)==='') throw new RuntimeException('Isolated API response missing for '.$action);
        $result=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
        echo json_encode(['result'=>$result]),PHP_EOL;
    }
} finally {
    if (is_resource($process)) {proc_terminate($process);foreach ($pipes as $pipe) fclose($pipe);proc_close($process);}
    if ($mockGeneration && $canvasId) {
        foreach (['aigc_short_drama_test_provider_receipt','aigc_short_drama_canvas_run','aigc_short_drama_generation_task','aigc_short_drama_asset','aigc_short_drama_canvas_poster_job','aigc_image_result','aigc_image_task','aigc_video_result','aigc_video_task','aigc_music_result','aigc_music_task'] as $table) Db::name($table)->where(['tenant_id'=>94011,'user_id'=>95011])->delete();
        Db::name('tenant_point_log')->where('tenant_id',94011)->delete();
        Db::name('user_account_log')->where('user_id',95011)->delete();
    }
    if ($agentConversation && $canvasId) {
        foreach (['outbox','event','message','run','thread'] as $kind) Db::name('aigc_short_drama_canvas_agent_'.$kind)->where(['canvas_id'=>$canvasId,'tenant_id'=>94011,'user_id'=>95011])->delete();
        Db::name('aigc_short_drama_canvas_agent_preference')->where(['tenant_id'=>94011,'user_id'=>95011])->delete();
    }
    if ($canvasId) Db::name('aigc_short_drama_canvas')->where(['id'=>$canvasId,'tenant_id'=>94011,'user_id'=>95011])->delete();
    foreach (array_reverse($inserted) as [$table,$id]) Db::name($table)->where('id',$id)->delete();
}
