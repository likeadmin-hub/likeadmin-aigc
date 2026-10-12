<?php
/** Pure key boundary plus rollback-only actual native insert and gateway replay hooks. */
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
use think\facade\Db;
use app\common\service\app\aigc_image\AigcImageSubmission as Submission;
use app\common\service\open_platform\{OpenCallContext as C,OpenStore as S,OpenAdmission,OpenFiles,OpenException};
use app\common\model\ai\AiConsumptionLog;
function submissionCheck($ok,$why){if(!$ok)throw new RuntimeException($why);}
$params=['batch_key'=>'scope-fixture-shared-key','prompt'=>'first'];
$pageKey=Submission::key($params,'aigc_image');submissionCheck($pageKey!==''&&$pageKey===Submission::key($params,'aigc_image')&&$pageKey!==Submission::key($params,'another_app'),'Page submission key compatibility lost');
C::enter('pure-open-fixture');try{submissionCheck(Submission::key($params,'aigc_image')==='','Open request reused a page batch_key');submissionCheck(Submission::key(['batch_key'=>['invalid-client-value']],'aigc_image')==='','Ignored page metadata changed API execution');}finally{C::leave();}
submissionCheck(Submission::key($params,'aigc_image')===$pageKey,'Open context changed subsequent ordinary page behavior');
if(($argv[1]??'')==='--pure'){echo "PASS API ignores page batch_key; ordinary app-scoped page keys preserved; no database or transport\n";exit;}
$db=config('database.connections.mysql');submissionCheck($db['hostname']==='127.0.0.1'&&$db['database']==='z_cn','Local rollback only');
$wallets=fn()=>[(float)Db::name('user')->where('id',1)->value('user_money'),(float)Db::name('tenant')->where('id',1)->value('point_balance')];$before=$wallets();$nativeCount=Db::name('aigc_image_task')->count();
Db::startTrans();try{
 request()->tenantId=1;request()->userId=1;$tag=bin2hex(random_bytes(8));$params['batch_key'].='-'.$tag;$pageKey=Submission::key($params,'aigc_image');
 $data=['tenant_id'=>1,'user_id'=>1,'prompt'=>'isolated '.$tag,'quantity'=>1,'status'=>'running','provider'=>'power_market','provider_params_json'=>[],'reference_images'=>[],'delete_time'=>0,'create_time'=>time(),'update_time'=>time()];
 $ui=Submission::claim($data,$pageKey);$uiReplay=Submission::claim($data,$pageKey);submissionCheck($ui['created']&&!$uiReplay['created']&&(int)$ui['task']['id']===(int)$uiReplay['task']['id'],'Page lost-acknowledgement replay created another task');
 $ids=[];$first=null;
 foreach(['original','different'] as $index=>$prompt){
  $raw=$params+['extra'=>'fixture'];$raw['prompt']=$prompt;$rid='req_scope_'.bin2hex(random_bytes(12));$idem='idem-'.$tag.'-'.$index;
  $row=['request_id'=>$rid,'tenant_id'=>1,'user_id'=>1,'key_id'=>0,'app_code'=>'aigc_image','kind'=>'tool','method'=>'POST','path'=>'/api/v1/tools/aigc_image/tasks','status'=>'accepted','billing_status'=>'none','active'=>1,'consumption_ids'=>'[]','snapshot'=>'{}','response_blob'=>'','payload_hash'=>OpenFiles::fingerprint($raw,[]),'idempotency_hash'=>hash('sha256','POST /api/v1/tools/aigc_image/tasks '.$idem)]+S::timestamp();$row['id']=S::table('request')->insertGetId($row);
  C::enter($rid);$claim=Submission::claim(array_replace($data,['prompt'=>$prompt]),Submission::key($raw,'aigc_image'));submissionCheck($claim['created']&&(int)$claim['task']['id']!==(int)$ui['task']['id'],'API reused an existing page task');$ids[]=(int)$claim['task']['id'];
  $consumption=AiConsumptionLog::create(['consume_no'=>'scope-'.$tag.'-'.$index,'tenant_id'=>1,'user_id'=>1,'app_code'=>'aigc_image','price_snapshot'=>[],'billing_status'=>'none','run_status'=>'running','usage_snapshot'=>[],'request_summary'=>[],'response_summary'=>[],'create_time'=>time(),'update_time'=>time()]);
  $fresh=S::table('request')->where('id',$row['id'])->find();submissionCheck((int)$fresh['tool_task_id']===(int)$claim['task']['id']&&in_array((int)$consumption['id'],S::json($fresh['consumption_ids']),true),'Native task/consumption hook association lost');submissionCheck(S::json($consumption['price_snapshot'])['_open_platform_request_id']===$rid,'Durable original billing request identity lost');C::leave();
  $replay=OpenAdmission::replay(['tenant_id'=>1,'user_id'=>1,'scopes'=>'[]'],'POST',$row['path'],$raw,$idem);submissionCheck($replay['request_id']===$rid,'Gateway idempotency stopped replaying its own request');$changed=$raw;$changed['prompt']='changed-again';try{OpenAdmission::replay(['tenant_id'=>1,'user_id'=>1,'scopes'=>'[]'],'POST',$row['path'],$changed,$idem);throw new RuntimeException('Gateway accepted conflicting parameters');}catch(OpenException $e){submissionCheck($e->errorType==='idempotency_conflict','Wrong replay conflict');}
  if($index===0)$first=$fresh;
 }
 submissionCheck($ids[0]!==$ids[1]&&(int)S::table('request')->where('id',$first['id'])->value('tool_task_id')===$ids[0],'Distinct API requests shared/replaced their immutable native mapping');submissionCheck($wallets()===$before,'Association fixture changed wallets');
 echo "PASS page batch replay vs gateway request idempotency, distinct API native tasks, original consumption hooks and owner mappings; rollback, no reservation or transport\n";
}finally{C::leave();Db::rollback();}
submissionCheck($wallets()===$before&&Db::name('aigc_image_task')->count()===$nativeCount,'Fixture failed to roll back');
