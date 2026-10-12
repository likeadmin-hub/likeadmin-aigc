<?php
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
use think\facade\Db;
use app\common\service\open_platform\OpenStore as S;
use app\common\service\open_platform\OpenManagement as M;
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
$config=config('database.connections.mysql');check($config['hostname']==='127.0.0.1'&&$config['database']==='z_cn','Local DB required');
Db::startTrans();try{
 $t=987654325;$u=123456784;$today=strtotime('today');
 $base=['tenant_id'=>$t,'user_id'=>$u,'kind'=>'market','app_code'=>'','key_id'=>1,'contract_id'=>1,'method'=>'POST','path'=>'/api/v1/chat/completions','status'=>'success','billing_status'=>'settled','active'=>0,'consumption_ids'=>'[]','snapshot'=>'{}','payload_hash'=>hash('sha256','fixture'),'response_blob'=>'','budget_user'=>0,'actual_user'=>2,'duration_ms'=>100,'create_time'=>$today,'update_time'=>$today];
 foreach([[],['kind'=>'tool','app_code'=>'aigc_music','path'=>'/api/v1/tools/aigc_music/tasks','status'=>'failed','actual_user'=>0,'duration_ms'=>300],['status'=>'running','active'=>1,'actual_user'=>0,'duration_ms'=>0,'create_time'=>strtotime('-1 day',$today)],['actual_user'=>1,'create_time'=>strtotime('-7 days',$today)],['create_time'=>strtotime('-14 days',$today)],['tenant_id'=>$t+1],['user_id'=>$u+1]] as $override)S::table('request')->insert(array_replace($base,$override,['request_id'=>'req_fixture_'.bin2hex(random_bytes(16))]));
 $a=M::analytics($t,$u,7);
 check(count($a['trend'])===7&&$a['trend'][0]['date']===date('Y-m-d',strtotime('-6 days',$today))&&$a['trend'][6]['date']===date('Y-m-d',$today),'Daily zero-filled window incorrect');
 check($a['metrics']['calls']===3&&$a['metrics']['charged']===2.&&$a['metrics']['success']===1&&$a['metrics']['failed']===1&&$a['metrics']['pending']===1,'Current ownership or status aggregate incorrect');
 check($a['metrics']['success_rate']===50.&&$a['metrics']['average_latency_ms']===200.,'Success denominator or latency included uncompleted request');
 check($a['previous']['calls']===1&&$a['previous']['charged']===1.,'Previous equal-length window incorrect');
 check($a['growth']['calls']['delta']===2&&$a['growth']['calls']['percent']===200.&&$a['growth']['charged']['percent']===100.,'Growth comparison incorrect');
 check(array_sum(array_column($a['distribution'],'calls'))===3&&count($a['top_capabilities'])===2,'Distribution or bounded ranking incorrect');
 check(!str_contains(S::encode($a),'actual_tenant')&&!str_contains(S::encode($a),'snapshot'),'Analytics exposes internal cost or private payload');
 $empty=M::analytics($t,$u+20,7);check($empty['metrics']['calls']===0&&$empty['metrics']['success_rate']===null&&$empty['growth']['calls']['percent']===null&&$empty['distribution']===[],'Empty statistics are fabricated');
 $new=M::analytics($t+1,$u,7);check($new['growth']['calls']['delta']===1&&$new['growth']['calls']['percent']===null,'Zero baseline growth must not divide by zero');
 try{M::analytics($t,$u,365);throw new RuntimeException('Unbounded window accepted');}catch(\app\common\service\open_platform\OpenException $e){check($e->errorType==='invalid_request','Unexpected invalid window error');}
 echo "PASS own analytics, daily gaps, equal periods, status denominator, growth and empty states\n";
}finally{Db::rollback();}
