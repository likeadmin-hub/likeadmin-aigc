<?php
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
use think\facade\Db;
use app\common\service\open_platform\OpenStore as S;
use app\common\service\open_platform\OpenGateway as G;
use app\common\service\open_platform\OpenToolRegistry as R;
use app\common\service\open_platform\OpenToolTasks as T;
use app\common\model\app\aigc_product_suite\AigcProductSuiteTask as P;
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
$db=config('database.connections.mysql');check($db['hostname']==='127.0.0.1'&&$db['database']==='z_cn','Local DB required');
Db::startTrans();try{
 request()->tenantId=1;request()->userId=1;request()->userInfo=['tenant_id'=>1,'user_id'=>1,'terminal'=>4];request()->source=app\common\enum\AdminTerminalEnum::USER;
 $task=P::create(['tenant_id'=>1,'user_id'=>1,'batch_no'=>'fixture_'.bin2hex(random_bytes(6)),'image_task_ids'=>[],'module_snapshot'=>[],'module_codes'=>[],'product_images'=>[],'status'=>'running','quantity'=>1,'delete_time'=>0,'create_time'=>time(),'update_time'=>time()]);
 $path='/api/v1/tools/aigc_product_suite/tasks/'.T::encode('aigc_product_suite',$task['id']);$rid='req_fixture_'.bin2hex(random_bytes(16));
 $row=['request_id'=>$rid,'tenant_id'=>1,'user_id'=>1,'key_id'=>0,'kind'=>'tool','app_code'=>'aigc_product_suite','method'=>'GET','path'=>$path,'status'=>'accepted','billing_status'=>'none','active'=>1,'consumption_ids'=>'[]','snapshot'=>'{}','payload_hash'=>hash('sha256','fixture'),'response_blob'=>'','budget_user'=>0,'budget_tenant'=>0]+S::timestamp();$row['id']=S::table('request')->insertGetId($row);
 $op=R::match('GET',$path)+['kind'=>'tool','history'=>true];$execute=new ReflectionMethod(G::class,'execute');$wire=$execute->invoke(null,request(),$row,$op,[],['user'=>0,'tenant'=>0,'snapshot'=>[]],false);
 check($wire->getCode()===200&&S::json($wire->getContent())['data']['status']==='running','Running task query altered task state');
 $saved=S::table('request')->where('request_id',$rid)->find();check((int)$saved['active']===0&&$saved['status']==='success'&&$saved['billing_status']==='none','Read query occupied a billable concurrency slot');
 check(T::billing(['consumption_ids'=>'[]','status'=>'failed','billing_status'=>'pending','actual_user'=>0])==='none','Uncharged failure incorrectly reported settled');
 S::table('request')->where('request_id',$rid)->update(['status'=>'running','active'=>1]);app\common\service\open_platform\OpenRecovery::refresh(S::table('request')->where('request_id',$rid)->find());check((int)S::table('request')->where('request_id',$rid)->value('active')===0,'Recovery retained a completed free read');
 echo "PASS running task remains running while free query ends without a billing slot\n";
}finally{app\common\service\open_platform\OpenCallContext::leave();Db::rollback();}
