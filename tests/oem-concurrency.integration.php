<?php
declare(strict_types=1);
require __DIR__.'/Support/OemDisposable.php'; require __DIR__.'/Support/OemFixtures.php';
use think\facade\Db;
use app\common\service\brand\TenantBrandService as Brand;
use app\common\service\brand\BrandWorkflowService as Flow;
if(!str_starts_with((string)getenv('PHP_DATABASE_DATABASE'),'oem_fault_')) throw new RuntimeException('Own concurrency database required');
function child(string $operation,array $input): array { $pipes=[]; $p=proc_open([PHP_BINARY,__DIR__.'/oem-concurrent-child.php',$operation,json_encode($input,JSON_THROW_ON_ERROR)],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes); fclose($pipes[0]);return [$p,$pipes]; }
function finish(array $child): string { [$p,$pipes]=$child;$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);oemCheck(proc_close($p)===0,'Concurrent operation failed: '.$out.$err);return $out; }
$f=oemFixture();Db::name('tenant_brand_quota_bucket')->insert(['tenant_id'=>$f['seller'],'package_id'=>$f['package'],'total_quota'=>1,'remaining_quota'=>1]);
$children=[];for($i=0;$i<6;$i++)$children[]=child('create',$f);
$orders=[];foreach($children as $c)$orders[]=json_decode(finish($c),true,512,JSON_THROW_ON_ERROR)['order_id'];
oemCheck(Db::name('tenant_brand_order')->whereIn('id',$orders)->where('reserve_status',1)->count()===1,'Concurrent reservations oversold one quota');
oemCheck((int)Db::name('tenant_brand_quota_bucket')->where('tenant_id',$f['seller'])->value('reserved_quota')===1,'Bucket preserves one frozen quota');
foreach($orders as $id){oemPaid($f,$id);Flow::review($f['seller'],9,$id,'approve','');}
oemCheck(Db::name('tenant_brand_order')->whereIn('id',$orders)->where('workflow_state','awaiting_quota')->count()===5,'Paid zero-stock orders remain reviewable');
echo "PASS real concurrent creation and quota reservation without overselling\n";
// Force the linked procurement callback to wait for its customer. A repeat purchase must not
// hold customer -> quota while the callback holds quota -> customer.
$g=oemFixture(); $id=Brand::createBrandOrder($g['seller'],$g['user'],4,$g['params'])['order_id'];oemPaid($g,$id);Flow::review($g['seller'],9,$id,'approve','');
$q=Flow::createQuota($g['seller'],9,4,$g['package'],1,$id);$quote=Db::name('tenant_brand_quota_order')->where('id',$q['order_id'])->find();
$sn='concurrency-quote-'.$quote['id'];$context=json_encode(['scope'=>'platform','tenant_id'=>$g['seller'],'channel'=>2,'merchant_id'=>'fixture-merchant','app_id'=>'fixture-app','seller_id'=>'','terminal'=>4]);
Db::name('tenant_brand_payment_intent')->insert(['order_from'=>Brand::FROM_QUOTA,'order_id'=>$quote['id'],'tenant_id'=>$g['seller'],'pay_sn'=>$sn,'channel'=>2,'merchant_id'=>'fixture-merchant','amount_cents'=>1999,'context'=>$context,'expires_at'=>time()+1800]);
$receipt=['pay_sn'=>$sn,'channel'=>2,'merchant_id'=>'fixture-merchant','app_id'=>'fixture-app','transaction_id'=>'concurrent-quote-'.$quote['id'],'amount_cents'=>1999,'currency'=>'CNY','status'=>'success'];
Db::startTrans();$callback=null;
try {
 Flow::seller($g['seller'],$id,true);$callback=child('payment',$receipt);$ready=json_decode(fgets($callback[1][1]),true);$connection=(int)$ready['connection'];
 $waiting=false;for($i=0;$i<100;$i++){if((int)(Db::query('SELECT COUNT(*) AS n FROM performance_schema.data_lock_waits w JOIN performance_schema.threads t ON t.THREAD_ID=w.REQUESTING_THREAD_ID WHERE t.PROCESSLIST_ID=?',[$connection])[0]['n']??0)){ $waiting=true;break;}usleep(20000);}
 oemCheck($waiting,'Callback must wait for customer lock; connections='.json_encode(Db::query('SELECT ID,STATE,INFO FROM information_schema.processlist WHERE DB=DATABASE()')).' watched='.$connection);
 $restored=Flow::createQuota($g['seller'],9,4,$g['package'],1,$id);oemCheck((int)$restored['order_id']===(int)$q['order_id'],'Repeat procurement must restore same order under callback overlap');
 Db::commit();finish($callback);$callback=null;
 oemCheck(Flow::owned($g['seller'],$g['user'],$id)['workflow_state']==='ready_submit','Callback commits money, inventory and reservation once');
 echo "PASS linked procurement callback and repeat purchase lock ordering\n";
} finally { Db::rollback();if($callback)finish($callback); }
