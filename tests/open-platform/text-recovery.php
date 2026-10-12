<?php
require dirname(__DIR__,2).'/vendor/autoload.php';
(new think\App())->initialize();
use think\facade\Db;
use app\common\service\open_platform\OpenStore as S;
use app\common\service\open_platform\OpenCallContext as C;
use app\common\service\open_platform\OpenTextLifecycle as L;
use app\common\service\open_platform\OpenRecovery as R;
use app\common\service\power\MarketTextModelRuntimeService as T;
use app\common\model\ai\AiConsumptionLog;
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
$config=config('database.connections.mysql');check($config['hostname']==='127.0.0.1'&&$config['database']==='z_cn','Local DB required');
Db::startTrans();
try{
 $rid='req_fixture_'.bin2hex(random_bytes(12));
 S::table('request')->insert(['request_id'=>$rid,'tenant_id'=>1,'user_id'=>1,'key_id'=>0,'kind'=>'tool','app_code'=>'aigc_llm','method'=>'POST','path'=>'/api/v1/tools/aigc_llm/tasks','status'=>'pending_confirmation','billing_status'=>'pending_confirmation','active'=>1,'budget_user'=>10,'budget_tenant'=>10,'snapshot'=>S::encode([]),'payload_hash'=>hash('sha256','fixture'),'consumption_ids'=>'[]','response_blob'=>'']+S::timestamp());
 C::enter($rid);$model=T::resolveModel(1,'65');$create=new ReflectionMethod(T::class,'createConsumption');$context=$create->invoke(null,1,1,$model,'fixture',[],['user_charge_points'=>0,'tenant_cost_points'=>0],0,'aigc_llm','aigc_llm_message',0);
 $cid=(int)$context['consumption']['id'];$wallet=(float)Db::name('user')->where('id',1)->value('user_money');
 L::defer($context,null,[],new RuntimeException('private provider diagnostic'));
 $c=AiConsumptionLog::findOrEmpty($cid);check($c['run_status']==='pending_confirmation'&&$c['billing_status']==='pending_confirmation','Uncertain submit must not become failed/refunded');
 $result=['content'=>'private generated answer','provider_request_id'=>'fixture-provider','usage'=>['prompt_tokens'=>2,'completion_tokens'=>3,'total_tokens'=>5]];
 L::recordResult($context,$result,$result['usage']);
 $captured=S::table('request')->where('request_id',$rid)->find();
 $saved=S::json(app\common\service\open_platform\OpenVault::open(S::json($captured['snapshot'])['native_text_result_blob']));
 check($saved===$result,'Provider result must be committed before settlement');
 check(AiConsumptionLog::findOrEmpty($cid)['run_status']==='success','Captured provider evidence lost on restart');
 check((float)Db::name('user')->where('id',1)->value('user_money')===$wallet,'Result capture must not charge');
 L::defer($context,$result,$result['usage'],new RuntimeException('point settlement unavailable'));
 $c=AiConsumptionLog::findOrEmpty($cid);check($c['run_status']==='success'&&$c['billing_status']==='settlement_failed','Known result must survive billing failure');
 check((int)$c['usage_snapshot']['total_tokens']===5,'Trusted usage lost');
 $row=S::table('request')->where('request_id',$rid)->find();check(!str_contains($row['snapshot'],'private generated answer'),'Private content leaked into snapshot');
 $snap=S::json($row['snapshot']);$snap['customer_callback']=['url'=>'https://example.com/open-callback','secret_blob'=>app\common\service\open_platform\OpenVault::seal(str_repeat('x',32))];S::table('request')->where('request_id',$rid)->update(['snapshot'=>S::encode($snap)]);$row=S::table('request')->where('request_id',$rid)->find();
 R::refresh($row);$row=S::table('request')->where('request_id',$rid)->find();check($row['status']==='success'&&$row['billing_status']==='settlement_failed'&&(int)$row['active']===1,'Recovery must retain unresolved settlement without provider resubmit');
 $response=S::json(app\common\service\open_platform\OpenVault::open($row['response_blob']));
 check($response['data']['content']===$result['content']&&$response['data']['billing']['billing_status']==='settlement_failed','Restart did not recover known output for idempotent replay');
 check($response['data']['task_id']===app\common\service\open_platform\OpenToolTasks::encode('aigc_llm',$row['id']),'Recovered text task ID changed');
 $owned=L::tasks(1,1,'GET tasks/{task_id}',['id'=>$row['id']]);check($owned['content']===$result['content'],'Owned text result missing');
 try{L::tasks(1,999999,'GET tasks/{task_id}',['id'=>$row['id']]);throw new RuntimeException('Cross-user output disclosed');}catch(app\common\service\open_platform\OpenException $e){check($e->httpStatus===404,'Wrong ownership error');}
 $receipt=$row['response_blob'];R::refresh($row);check(S::table('request')->where('request_id',$rid)->value('response_blob')===$receipt,'Recovery rewrote existing replay receipt');
 check(S::table('webhook')->where('request_id',$rid)->count()===1,'Recovered notification missing or duplicated');
 S::table('request')->where('request_id',$rid)->update(['path'=>'/api/v1/tools/aigc_llm/stream','response_blob'=>'']);R::refresh(S::table('request')->where('request_id',$rid)->find());
 $stream=S::table('request')->where('request_id',$rid)->find();check($stream['response_type']==='text/event-stream'&&str_starts_with(app\common\service\open_platform\OpenVault::open($stream['response_blob']),"event: done\ndata: "),'Stream recovery lost terminal event');
 check((float)Db::name('user')->where('id',1)->value('user_money')===$wallet,'Recovery deducted without approval');
 check(app\common\service\open_platform\OpenToolTasks::billing($row)==='settlement_failed','Public billing classification lost');
 echo "PASS uncertain text submit, encrypted known result, owned task output, replay/stream and deduplicated notification recovery without refund/recharge\n";
}finally{C::leave();Db::rollback();}
