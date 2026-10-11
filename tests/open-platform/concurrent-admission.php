<?php
/** Concurrent SQL admission against a disposable tenant; no business execution or point writes. */
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
use think\facade\Db;
use app\common\service\open_platform\OpenStore as S;
use app\common\service\open_platform\OpenAdmission as Admission;
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
$db=config('database.connections.mysql');check(PHP_SAPI==='cli'&&$db['hostname']==='127.0.0.1'&&$db['database']==='z_cn','Local concurrency fixture only');
if(($argv[1]??'')==='--child'){
 [$tenant,$user,$keyId]=array_map('intval',array_slice($argv,2,3));$barrier=(string)($argv[5]??'');check(preg_match('#^/tmp/opf_concurrency_[a-f0-9]{24}$#D',$barrier),'Invalid fixture barrier');$deadline=microtime(true)+10;while(!is_file($barrier)&&microtime(true)<$deadline)usleep(10000);check(is_file($barrier),'Barrier timed out');
 request()->tenantId=$tenant;request()->userId=$user;request()->source=app\common\enum\AdminTerminalEnum::USER;request()->userInfo=['tenant_id'=>$tenant,'user_id'=>$user,'terminal'=>4];
 $key=S::owner('key',$tenant,$user)->where('id',$keyId)->find();check($key&&str_starts_with($key['name'],'opf-concurrency-'),'Missing disposable test key');
 try{$row=Admission::begin($key,'POST','/api/v1/tools/aigc_image/tasks',[],['kind'=>'tool','app_code'=>'aigc_image','billable'=>true,'history'=>true],['user'=>75,'tenant'=>75,'snapshot'=>[]],'concurrent-fixture-'.$keyId)['row'];echo S::encode(['accepted'=>true,'request_id'=>$row['request_id']]);}
 catch(app\common\service\open_platform\OpenException $e){check(in_array($e->errorType,['insufficient_quota','concurrency_exceeded'],true),'Unexpected admission rejection');echo S::encode(['accepted'=>false,'error'=>$e->errorType]);}exit(0);
}
$tag=bin2hex(random_bytes(12));$barrier='/tmp/opf_concurrency_'.$tag;$tenant=$user=0;$processes=[];$report=['parallel_keys'=>8,'provider_calls'=>0,'point_mutations'=>0];
try{
 $tenant=Db::name('tenant')->insertGetId(['sn'=>'opf'.substr($tag,0,10),'name'=>'opf-concurrency-'.$tag,'disable'=>0,'tactics'=>0,'point_balance'=>100,'delete_time'=>null]+S::timestamp());
 $user=Db::name('user')->insertGetId(['tenant_id'=>$tenant,'sn'=>random_int(10000000,99999999),'account'=>'opf-concurrency-'.$tag,'nickname'=>'disposable fixture','user_money'=>100,'delete_time'=>null]+S::timestamp());
 S::table('setting')->insert(['tenant_id'=>$tenant,'enabled'=>0,'config'=>S::encode(S::defaults())]+S::timestamp());
 for($i=0;$i<8;$i++){$key=S::table('key')->insertGetId(['tenant_id'=>$tenant,'user_id'=>$user,'name'=>'opf-concurrency-'.$tag,'key_hash'=>hash('sha256',random_bytes(32)),'prefix'=>'disposable','status'=>1,'scopes'=>'["aigc_image"]','ip_allowlist'=>'[]']+S::timestamp());$pipes=[];$process=proc_open([PHP_BINARY,__FILE__,'--child',(string)$tenant,(string)$user,(string)$key,$barrier],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);check(is_resource($process),'Child process unavailable');fclose($pipes[0]);$processes[]=[$process,$pipes];}
 touch($barrier);$results=[];foreach($processes as [$process,$pipes]){$output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);check($exit===0&&$errors===''&&json_validate_compat($output),'Concurrent child failed');$results[]=S::json($output);}$processes=[];
 $accepted=count(array_filter($results,fn($row)=>$row['accepted']));$pending=(float)S::owner('request',$tenant,$user)->where('active',1)->sum('budget_user');check($accepted===1&&$pending===75.,'Parallel keys bypassed shared wallet reservation');
 check((float)Db::name('user')->where('id',$user)->value('user_money')===100.&&(float)Db::name('tenant')->where('id',$tenant)->value('point_balance')===100.,'Admission mutated fixture wallets');check(Db::name('user_account_log')->where('user_id',$user)->count()===0&&Db::name('tenant_point_log')->where('tenant_id',$tenant)->count()===0,'Admission generated point ledger');
 $report+=['accepted'=>$accepted,'rejected'=>8-$accepted,'pending_budget'=>$pending,'result'=>'passed'];echo "PASS eight independent PHP processes and Keys share atomic user/tenant budget, with no provider calls or point writes\n";
}finally{
 foreach($processes as [$process,$pipes]){proc_terminate($process);foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);proc_close($process);}
 @unlink($barrier);if($tenant){S::table('request')->where('tenant_id',$tenant)->delete();S::table('key')->where(['tenant_id'=>$tenant,'name'=>'opf-concurrency-'.$tag])->delete();S::table('setting')->where('tenant_id',$tenant)->delete();}if($user)Db::name('user')->where(['id'=>$user,'account'=>'opf-concurrency-'.$tag])->delete();if($tenant)Db::name('tenant')->where(['id'=>$tenant,'name'=>'opf-concurrency-'.$tag])->delete();
 $report['fixture_removed']=true;file_put_contents(dirname(__DIR__,2).'/runtime/open-platform-acceptance/concurrent-admission.json',S::encode($report));
}
function json_validate_compat(string $input): bool{json_decode($input);return json_last_error()===JSON_ERROR_NONE;}
