<?php
/** Loopback-only gateway, frozen-price settlement and replay acceptance, rolled back in full. */
require dirname(__DIR__,2).'/vendor/autoload.php'; (new think\App())->initialize();
use think\facade\Db;
use app\common\service\open_platform\{OpenGateway as G,OpenStore as S,OpenVault as V,OpenTransport as T,OpenMarketBilling as B,OpenCallContext};
function checkTextWire($ok,$why){if(!$ok)throw new RuntimeException($why);}
$c=config('database.connections.mysql');checkTextWire(PHP_SAPI==='cli'&&$c['hostname']==='127.0.0.1'&&$c['database']==='z_cn','Local rollback test only');
request()->tenantId=1; request()->userId=1;
$wallets=fn()=>[Db::name('user')->where(['tenant_id'=>1,'id'=>1])->value('user_money'),Db::name('tenant')->where('id',1)->value('point_balance')]; $before=$wallets();
$source=['base_url'=>'http://127.0.0.1:19875','api_key'=>'fixture','ssl_verify'=>true];
$execute=new ReflectionMethod(G::class,'execute');$replay=new ReflectionMethod(G::class,'replayResponse');
Db::startTrans();
try {
    $s=Db::name('update_source')->where('status',1)->order('id','desc')->find(); checkTextWire($s,'Missing local source'); unset($s['id']);
    $s['name']='local-text-wire-fixture';$s['base_url']=$s['online_base_url']=$source['base_url'];$s['license_key']=$s['online_license_key']='fixture';$s['dev_mode']=0;
    Db::name('update_source')->insert($s);
    checkTextWire(app\common\service\update\UpdateSourceClient::getSource()['active_base_url']===$source['base_url'],'Loopback fence failed');
    // Exercise authenticated admission and actual Response::send, not just the executor.
    foreach([0,1] as $tenant){$settings=S::settings($tenant);$settings['models']=array_values(array_unique(array_merge($settings['models'],['2'])));$settings['tenant_enabled']=true;$settings['features']['keys']=true;\app\common\service\open_platform\OpenManagement::persistSettings($tenant,true,$settings);}
    $key=\app\common\service\open_platform\OpenKeyService::create(1,1,['name'=>'rollback text admission','scopes'=>['2'],'ip_allowlist'=>['127.0.0.1/32']]);
    $contract=['code'=>'fixture.text.admission.'.bin2hex(random_bytes(4)),'kind'=>'market','product_id'=>2,'method'=>'POST','path'=>'/api/v1/chat/completions','config'=>['executor'=>'json','role'=>'submit','billable'=>false,'model'=>'fixture','input'=>['type'=>'object','properties'=>['model'=>['type'=>'string'],'stream'=>['type'=>'boolean']]],'example'=>['model'=>'fixture'],'responses'=>['200'=>['description'=>'Native fixture']]]];
    $valid=\app\common\service\open_platform\OpenContracts::validate($contract);
    S::table('contract')->insert($valid+['version'=>1,'status'=>'published']+S::timestamp());
    foreach([false,true] as $stream){
        $params=['model'=>'fixture','fixture_case'=>'chat','stream'=>$stream];$expected=(new T)->send($source,'POST',$contract['path'],$params);
        $request=new #[\AllowDynamicProperties] class($params,$key['key']) {
            function __construct(private array $input,private string $token){}
            function method(){return 'POST';} function post(){return $this->input;} function file(){return [];}
            function getInput(){return json_encode($this->input);} function server($name,$default=''){return $name==='REMOTE_ADDR'?'127.0.0.1':$default;}
            function header($name,$default=''){return ['content-type'=>'application/json','authorization'=>'Bearer '.$this->token,'idempotency-key'=>'text-admission-'.(int)$this->input['stream']][$name]??$default;}
        };
        $response=G::handle($request,$contract['path']);checkTextWire($response instanceof \app\common\service\open_platform\OpenStreamResponse,'Native JSON contract did not defer real-format forwarding');
        ob_start();$response->send();$body=ob_get_clean();checkTextWire($body===$expected['body'],'Authenticated native response changed');
        $second=G::handle($request,$contract['path']);checkTextWire($second->getContent()===$body,'Authenticated idempotent replay changed');
    }
    foreach (['chat'=>'/api/v1/chat/completions','responses'=>'/api/v1/responses','claude'=>'/api/v1/messages','legacy'=>'/api/v1/completions','missing_usage'=>'/api/v1/chat/completions','reject'=>'/api/v1/messages','unavailable'=>'/api/v1/responses','stream_error'=>'/api/v1/chat/completions','truncated'=>'/api/v1/chat/completions','disconnect'=>'/api/v1/chat/completions','json_on_stream'=>'/api/v1/chat/completions','unexpected_sse'=>'/api/v1/chat/completions'] as $case=>$path) {
        foreach ([false,true] as $stream) {
            if(in_array($case,['stream_error','truncated','disconnect'],true)&&!$stream)continue;
            $params=['model'=>'fixture','fixture_case'=>$case,'stream'=>$stream];
            $wire=(new T)->send($source,'POST',$path,$params,[],null,[],'application/json',true);
            $op=['kind'=>'market','executor'=>'json','role'=>'submit','billable'=>true,'history'=>false,'product_id'=>2,'model'=>'fixture','path'=>$path,'method'=>'POST','response_adapter'=>'retail_market_v1'];
            // Fixtures exercise frozen directional rates through the real reservation/settlement service.
            $rules=[['sku_id'=>2,'sku_key'=>'fixture_input','usage_unit'=>'token','basis'=>'usage','usage_path'=>'usage.input_tokens','max_units'=>100,'units'=>100,'unit_size'=>1000,'prices'=>['user'=>1.4,'tenant'=>1]],['sku_id'=>3,'sku_key'=>'fixture_output','usage_unit'=>'token','basis'=>'usage','usage_path'=>'usage.output_tokens','max_units'=>100,'units'=>100,'unit_size'=>1000,'prices'=>['user'=>2.8,'tenant'=>2]]];
            $budget=['user'=>0.42,'tenant'=>0.30,'snapshot'=>['billing_owner'=>'gateway','contract'=>$op,'rules'=>$rules,'product_id'=>2,'customer_callback'=>['url'=>'https://example.com/never-delivered-fixture','secret_blob'=>V::seal(str_repeat('s',32))]]];
            $id='req_text_fixture_'.bin2hex(random_bytes(10));
            $row=['request_id'=>$id,'tenant_id'=>1,'user_id'=>1,'key_id'=>0,'kind'=>'market','method'=>'POST','path'=>$path,'status'=>'accepted','billing_status'=>'pending','active'=>1,'budget_user'=>$budget['user'],'budget_tenant'=>$budget['tenant'],'consumption_ids'=>'[]','snapshot'=>S::encode($budget['snapshot']),'payload_hash'=>hash('sha256',$id),'response_blob'=>'']+S::timestamp();
            $row['id']=S::table('request')->insertGetId($row);
            // Native text uses deferred forwarding even when a contract used to say JSON.
            checkTextWire(G::textProtocol($op,$path)!=='','Text protocol not selected');
            $request=new class { function header($name,$default=''){return $name==='content-type'?'application/json':$default;} function file(){return [];} };
            ob_start();$execute->invoke(null,$request,$row,$op,$params,$budget,true);$emitted=ob_get_clean();
            checkTextWire($emitted===$wire['body'],'Raw business bytes changed: '.$case);
            $saved=S::table('request')->where('id',$row['id'])->find();
            checkTextWire(V::open($saved['response_blob'])===$wire['body']&&(int)$saved['response_code']===$wire['status'],'Stored raw wire or status changed');
            $headers=G::responseHeaders($wire['headers'],$row);
            checkTextWire(($headers['x-request-id']??'')==='upstream-fixture-id'&&$headers['X-Open-Request-Id']===$id&&$headers['x-ratelimit-remaining-requests']==='17','Native headers or local receipt lost');
            $pending=in_array($case,['unavailable','stream_error','truncated','disconnect'],true);
            $expected=$pending?'pending_confirmation':($case==='missing_usage'?'pending_usage':($case==='reject'?'refunded':'settled'));
            checkTextWire($saved['billing_status']===$expected,'Settlement mismatch '.$case.' '.$saved['billing_status']);
            if($expected==='settled')checkTextWire((float)$saved['actual_user']===0.04&&(float)$saved['actual_tenant']===0.03,'Frozen input/output rates differ');
            if($expected==='refunded')checkTextWire((float)$saved['actual_user']===0.&&!(int)$saved['active'],'Definite rejection not refunded');
            if($pending||$expected==='pending_usage')checkTextWire((int)$saved['active']===1&&(float)$saved['actual_user']===0.42,'Unknown usage/partial output lost reservation '.$case.' '.json_encode(array_intersect_key($saved,array_flip(['active','budget_user','billing_status']))));
            $callbackCount=S::table('webhook')->where('request_id',$id)->count();
            checkTextWire($callbackCount===(in_array($expected,['settled','refunded'],true)?1:0),'Terminal callback missing or uncertain callback emitted');
            $ledgerCount=Db::name('user_account_log')->where('source_sn','like',$id.'%')->count();
            $rep=$replay->invoke(null,$saved,$path);
            if($case==='disconnect')checkTextWire($rep->getCode()===409,'Incomplete transport replayed as a complete success');
            else checkTextWire($rep->getContent()===$wire['body']&&$rep->getCode()===$wire['status'],'Idempotent wire replay changed');
            checkTextWire(Db::name('user_account_log')->where('source_sn','like',$id.'%')->count()===$ledgerCount,'Replay charged twice');
            checkTextWire(S::table('webhook')->where('request_id',$id)->count()===$callbackCount,'Replay enqueued duplicate notification');
            if($expected==='settled'){B::settle($saved,['usage'=>['input_tokens'=>99,'output_tokens'=>99]]);checkTextWire(Db::name('user_account_log')->where('source_sn','like',$id.'%')->count()===$ledgerCount,'Duplicate settlement charged twice');}
        }
    }
} finally { OpenCallContext::leave(); Db::rollback(); }
checkTextWire($wallets()===$before,'Rollback changed real wallet values');
echo "PASS four native JSON/SSE protocols, raw bytes and headers, response-format fallback, frozen directional prices, uncertain stream/HTTP and missing-usage holds, rejection refund, identical replay and no duplicate charge; loopback fixtures and rollback only\n";
