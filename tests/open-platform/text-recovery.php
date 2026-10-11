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
 S::table('request')->insert(['request_id'=>$rid,'tenant_id'=>1,'user_id'=>1,'key_id'=>0,'kind'=>'tool','app_code'=>'aigc_llm','method'=>'POST','path'=>'/api/v1/tools/aigc_llm/tasks','status'=>'pending_confirmation','billing_status'=>'pending_confirmation','active'=>1,'budget_user'=>10,'budget_tenant'=>10,'snapshot'=>S::encode([])]+S::timestamp());
 C::enter($rid);$model=T::resolveModel(1,'65');$create=new ReflectionMethod(T::class,'createConsumption');$context=$create->invoke(null,1,1,$model,'fixture',[],['user_charge_points'=>0,'tenant_cost_points'=>0],0,'aigc_llm','aigc_llm_message',0);
 $cid=(int)$context['consumption']['id'];$wallet=(float)Db::name('user')->where('id',1)->value('user_money');
 L::defer($context,null,[],new RuntimeException('private provider diagnostic'));
 $c=AiConsumptionLog::findOrEmpty($cid);check($c['run_status']==='pending_confirmation'&&$c['billing_status']==='pending_confirmation','Uncertain submit must not become failed/refunded');
 $result=['content'=>'private generated answer','provider_request_id'=>'fixture-provider','usage'=>['prompt_tokens'=>2,'completion_tokens'=>3,'total_tokens'=>5]];
 L::defer($context,$result,$result['usage'],new RuntimeException('point settlement unavailable'));
 $c=AiConsumptionLog::findOrEmpty($cid);check($c['run_status']==='success'&&$c['billing_status']==='settlement_failed','Known result must survive billing failure');
 check((int)$c['usage_snapshot']['total_tokens']===5,'Trusted usage lost');
 $row=S::table('request')->where('request_id',$rid)->find();check(!str_contains($row['snapshot'],'private generated answer'),'Private content leaked into snapshot');
 R::refresh($row);$row=S::table('request')->where('request_id',$rid)->find();check($row['status']==='success'&&$row['billing_status']==='settlement_failed'&&(int)$row['active']===1,'Recovery must retain unresolved settlement without provider resubmit');
 check((float)Db::name('user')->where('id',1)->value('user_money')===$wallet,'Recovery deducted without approval');
 check(app\common\service\open_platform\OpenToolTasks::billing($row)==='settlement_failed','Public billing classification lost');
 echo "PASS uncertain text submit, encrypted known result, trusted usage and recovery without refund/recharge\n";
}finally{C::leave();Db::rollback();}
