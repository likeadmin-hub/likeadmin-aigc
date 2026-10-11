<?php
require dirname(__DIR__,2).'/vendor/autoload.php';
(new think\App())->initialize();
use think\facade\Db;
use app\common\service\open_platform\OpenStore as S;
use app\common\service\open_platform\OpenKeyService as K;
use app\common\service\open_platform\OpenAdmission as A;
use app\common\service\open_platform\OpenCallContext as Context;
use app\common\service\open_platform\OpenMarketBilling as Billing;
use app\common\model\ai\AiConsumptionLog;
function check($ok,$reason){if(!$ok)throw new RuntimeException($reason);}
$c=config('database.connections.mysql');if($c['hostname']!=='127.0.0.1'||$c['database']!=='z_cn')throw new RuntimeException('Local DB required');
Db::startTrans();
try{
 $suffix=bin2hex(random_bytes(5));
 $t=Db::name('tenant')->insertGetId(['sn'=>'open'.$suffix,'name'=>'rollback open fixture','disable'=>0,'tactics'=>0,'point_balance'=>1000,'delete_time'=>null,'create_time'=>time(),'update_time'=>time()]);
 request()->tenantId=$t;request()->source=app\common\enum\AdminTerminalEnum::USER;
 $u=Db::name('user')->insertGetId(['tenant_id'=>$t,'sn'=>random_int(10000000,99999999),'account'=>'open-fixture-'.$suffix,'nickname'=>'fixture','user_money'=>1000,'delete_time'=>null,'create_time'=>time(),'update_time'=>time()]);
 request()->userId=$u;request()->userInfo=['user_id'=>$u,'tenant_id'=>$t];
 $config=S::defaults();$config['concurrency']=5;S::table('setting')->insert(['tenant_id'=>$t,'enabled'=>1,'config'=>S::encode($config)]+S::timestamp());
 $key=K::create($t,$u,['name'=>'billing fixture']);$key=S::table('key')->where('id',$key['id'])->find();
 $operation=['kind'=>'market','billable'=>true,'contract_id'=>0,'product_id'=>1,'executor'=>'json'];
 $budget=['user'=>10,'tenant'=>4,'snapshot'=>['product_id'=>1,'contract'=>$operation,'rules'=>[['sku_id'=>1,'basis'=>'usage','max_units'=>2,'usage_path'=>'usage.count','units'=>2,'unit_size'=>1,'prices'=>['user'=>5,'tenant'=>2]]]]];
 $admitted=A::begin($key,'POST','/api/v1/tasks',['fixture'=>1],$operation,$budget,'fixture-key');$row=$admitted['row'];
 check(A::begin($key,'POST','/api/v1/tasks',['fixture'=>1],$operation,$budget,'fixture-key')['replay']===true,'Idempotency replay');
 try{A::begin($key,'POST','/api/v1/tasks',['fixture'=>2],$operation,$budget,'fixture-key');throw new RuntimeException('Changed idempotency payload accepted');}catch(app\common\service\open_platform\OpenException $e){check($e->httpStatus===409,'Conflict response');}
 Context::enter($row['request_id']);Billing::reserve($row,$budget);Context::leave();
 check((float)Db::name('user')->where('id',$u)->value('user_money')===990.,'Reservation did not protect user wallet');
 $saved=S::table('request')->where('id',$row['id'])->find();check(count(S::json($saved['consumption_ids']))===1,'Consumption association missing');check((float)$saved['actual_user']===10.&&(float)$saved['budget_user']===0.,'Observed debit or remaining budget wrong');
 $log=AiConsumptionLog::where('consume_no',$row['request_id'])->findOrEmpty();check(S::json($log['price_snapshot'])['_open_platform_request_id']===$row['request_id'],'Durable worker billing context missing');
 Billing::settle($row,['usage'=>['count'=>1]]);Billing::settle($row,['usage'=>['count'=>1]]);
 check((float)Db::name('user')->where('id',$u)->value('user_money')===995.,'Final user balance or duplicate settlement wrong');
 check((float)Db::name('tenant')->where('id',$t)->value('point_balance')===998.,'Tenant cost wrong');
 check(Db::name('user_account_log')->where('user_id',$u)->count()===2,'Independent user debit/refund ledger mismatch');
 check(Db::name('tenant_point_log')->where('tenant_id',$t)->count()===2,'Independent tenant ledger mismatch');
 $saved=S::table('request')->where('id',$row['id'])->find();check($saved['billing_status']==='settled'&&(int)$saved['active']===0&&(float)$saved['actual_user']===5.,'Request terminal billing wrong');
 $next=A::begin($key,'POST','/api/v1/tasks',['fixture'=>3],$operation,$budget,'fixture-missing-usage')['row'];Context::enter($next['request_id']);Billing::reserve($next,$budget);Context::leave();Billing::settle($next,[]);
 check(S::table('request')->where('id',$next['id'])->value('billing_status')==='pending_usage','Missing trusted usage was estimated or refunded');
 echo "PASS rollback-isolated admission, replay conflicts, durable debit association, real point ledgers, partial settlement, repeated settlement and missing usage\n";
}finally{Context::leave();Db::rollback();}
