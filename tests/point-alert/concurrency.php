<?php
require dirname(__DIR__,2).'/vendor/autoload.php';
(new think\App())->initialize();
use think\facade\Db;
use app\common\service\point_alert\AlertStore as S;
use app\common\service\point_alert\AlertService as A;
$c=config('database.connections.mysql');if($c['hostname']!=='127.0.0.1'||$c['database']!=='z_cn')throw new RuntimeException('Local DB only');
$t=987654310;$u=987654309;$now=time();
if(Db::name('tenant')->where('id',$t)->find()||Db::name('user')->where('id',$u)->find())throw new RuntimeException('Fixture ID occupied');
if(S::table('delivery')->whereIn('status',['pending','sending'])->count())throw new RuntimeException('Concurrent dispatch test requires no unrelated queued deliveries');
$bootstrap= <<<'CODE'
require "/www/wwwroot/likeadmin_aigc_saas/server/vendor/autoload.php";
spl_autoload_register(function($c){$p="app\\common\\service\\point_alert\\";if(str_starts_with($c,$p))require "/tmp/point-alert-code/".substr($c,strlen($p)).".php";},true,true);
(new think\App())->initialize();
CODE;
$log='/tmp/point-alert-concurrency-'.getmypid().'.log';
function workers(string $code,int $n=8):void{
 global $bootstrap;$jobs=[];
 for($i=0;$i<$n;$i++){$p=proc_open([PHP_BINARY,'-r',$bootstrap.$code],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,'/www/wwwroot/likeadmin_aigc_saas/server');if(!is_resource($p))throw new RuntimeException('worker unavailable');fclose($pipes[0]);$jobs[]=[$p,$pipes];}
 foreach($jobs as [$p,$pipes]){$out=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($p)!==0)throw new RuntimeException($out);}
}
try{
 Db::name('tenant')->insert(['id'=>$t,'sn'=>'alert_concurrency','name'=>'concurrency','point_balance'=>120,'create_time'=>$now,'tactics'=>0]);
 Db::name('user')->insert(['id'=>$u,'tenant_id'=>$t,'sn'=>$u,'account'=>'alert_concurrency','nickname'=>'concurrency','user_money'=>120,'create_time'=>$now]);
 S::saveRules($t,['enabled'=>true,'thresholds'=>['1000','200','50','0'],'max_cycle'=>2,'max_daily'=>2,'min_interval_minutes'=>1],0);
 $check='for($i=0;$i<10;$i++)\\app\\common\\service\\point_alert\\AlertService::check('.$t.','.$u.','.$now.');';
 workers($check);
 if(S::table('round')->where('tenant_id',$t)->count()!==1)throw new RuntimeException('Concurrent admission duplicated');
 $dispatch='\\app\\common\\service\\point_alert\\AlertService::dispatch(100,function($d){file_put_contents('.var_export($log,true).',$d["id"].PHP_EOL,FILE_APPEND|LOCK_EX);usleep(50000);return ["status"=>"success","result"=>"fake"];});';
 workers($dispatch);
 $ids=file($log,FILE_IGNORE_NEW_LINES);if(count($ids)!==1||count(array_unique($ids))!==1)throw new RuntimeException('Concurrent delivery duplicated');
 Db::name('user')->where('id',$u)->update(['user_money'=>40]);workers(str_replace((string)$now,(string)($now+61),$check));workers($dispatch);
 Db::name('user')->where('id',$u)->update(['user_money'=>0]);workers(str_replace((string)$now,(string)($now+122),$check));
 $state=S::json(S::table('account')->where(['tenant_id'=>$t,'user_id'=>$u])->value('state'));
 if(S::table('round')->where('tenant_id',$t)->count()!==2||$state['cycle_count']!==2||$state['day_count']!==2||count(file($log))!==2)throw new RuntimeException('Concurrent cap exceeded');
 echo "PASS point-alert 8-process admission, delivery and cap race\n";
}finally{
 $ids=S::table('round')->where('tenant_id',$t)->column('id');if($ids)S::table('delivery')->whereIn('round_id',$ids)->delete();
 foreach(['round','account','dirty','contact'] as $table)S::table($table)->where('tenant_id',$t)->delete();
 S::table('setting')->where('owner_id',$t)->delete();S::table('cursor')->where('owner_id',$t)->delete();
 Db::name('user')->where(['id'=>$u,'tenant_id'=>$t])->delete();Db::name('tenant')->where('id',$t)->delete();if(is_file($log))unlink($log);
}
