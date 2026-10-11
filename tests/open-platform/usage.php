<?php
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
use think\facade\Db;
use app\common\service\open_platform\OpenStore as S;
use app\common\service\open_platform\OpenManagement as M;
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
$config=config('database.connections.mysql');check($config['hostname']==='127.0.0.1'&&$config['database']==='z_cn','Local DB required');
Db::startTrans();try{
 $t=987654324;$u=123456783;
 $base=['tenant_id'=>$t,'user_id'=>$u,'kind'=>'tool','app_code'=>'aigc_llm','key_id'=>10,'contract_id'=>20,'method'=>'POST','path'=>'/api/v1/tools/aigc_llm/tasks','status'=>'success','billing_status'=>'settled','active'=>0,'consumption_ids'=>'[]','snapshot'=>S::encode(['native_text_model'=>['model_code'=>'frozen-model']]),'payload_hash'=>hash('sha256','fixture'),'response_blob'=>'','budget_user'=>200,'actual_user'=>2,'create_time'=>time(),'update_time'=>time()];
 foreach([[],['active'=>1,'status'=>'running','billing_status'=>'pending_usage','actual_user'=>0,'budget_user'=>3],['tenant_id'=>$t+1],['user_id'=>$u+1],['key_id'=>11,'snapshot'=>S::encode(['contract'=>['model'=>'other-model']]),'actual_user'=>4]] as $override)S::table('request')->insert(array_replace($base,$override,['request_id'=>'req_fixture_'.bin2hex(random_bytes(16))]));
 $all=M::usage($t,$u,[]);check($all['calls']===3&&(float)$all['charged']===6.,'Usage ownership or recorded charges mismatch');
 check((float)$all['pending_budget']===3.,'Inactive historical reservation counted as pending');
 $model=M::usage($t,$u,['model'=>'frozen-model']);check($model['calls']===2&&(float)$model['charged']===2.&&(float)$model['pending_budget']===3.,'Snapshot model filter mismatch');
 $key=M::usage($t,$u,['key_id'=>11]);check($key['calls']===1&&(float)$key['charged']===4.,'Key filter mismatch');
 check(M::usage($t,$u,['model'=>"other-model' OR 1=1 --"])['calls']===0,'Untrusted model filter escaped its bound parameter');
 check(M::usage($t,$u,['app_code'=>'aigc_image'])['calls']===0,'Tool filter ignored');
 check(M::usage($t,$u,['end'=>time()-1])['calls']===0,'Date window ignored');
 foreach([['method'=>'POST','path'=>'/api/v1/tools/aigc_llm/tasks'],['method'=>'GET','path'=>'/api/v1/tools/aigc_llm/tasks/ot_aigc_llm_123'],['method'=>'POST','path'=>'/api/v1/tools/aigc_llm/tasks/ot_aigc_llm_123/cancel']] as $override)S::table('request')->insert(array_replace($base,$override,['tool_task_id'=>'123','request_id'=>'req_fixture_'.bin2hex(random_bytes(16))]));
 $tasks=M::requests($t,$u,['tasks'=>true]);check($tasks['count']===1&&$tasks['lists'][0]['method']==='POST'&&$tasks['lists'][0]['path']==='/api/v1/tools/aigc_llm/tasks','Historical query/cancel records were displayed as distinct generated tasks');
 echo "PASS own usage, active reservations, frozen model, Key/tool/date filters and parameter binding\n";
}finally{Db::rollback();}
