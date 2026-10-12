<?php
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
use think\facade\Db;
use app\common\service\open_platform\OpenStore as S;
use app\common\service\open_platform\OpenCallContext as Context;
use app\common\service\open_platform\OpenAdmission as Admission;
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
$db=config('database.connections.mysql');check($db['hostname']==='127.0.0.1'&&$db['database']==='z_cn','Local rollback test only');
Db::startTrans();try{
 $id='req_lease_fixture_'.bin2hex(random_bytes(12));$row=['request_id'=>$id,'tenant_id'=>1,'user_id'=>1,'key_id'=>0,'kind'=>'tool','app_code'=>'aigc_music','method'=>'POST','path'=>'/api/v1/tools/aigc_music/tasks','status'=>'running','billing_status'=>'pending','active'=>1,'consumption_ids'=>'[]','snapshot'=>'{}','payload_hash'=>hash('sha256',$id),'response_blob'=>'','budget_user'=>65,'budget_tenant'=>1,'lease_token'=>'old-worker','lease_expire'=>time()+60]+S::timestamp();S::table('request')->insert($row);
 Context::enter($id,'old-worker');Admission::finish($id,['error_message'=>'same value']);Admission::finish($id,['error_message'=>'same value']);
 S::table('request')->where('request_id',$id)->update(['lease_token'=>'new-worker','lease_expire'=>time()+60]);
 foreach([
  fn()=>Admission::finish($id,['status'=>'success','active'=>0,'lease_token'=>'','lease_expire'=>0]),
  fn()=>Context::observePoints(1,1,1,65,'lease-fixture',[],false),
  fn()=>app\common\service\open_platform\OpenRecovery::refresh(array_replace($row,['id'=>(int)S::table('request')->where('request_id',$id)->value('id')])),
  fn()=>app\common\service\open_platform\OpenMarketBilling::settle($row,[],true),
 ] as $operation){$blocked=false;try{$operation();}catch(app\common\service\open_platform\OpenException $e){$blocked=$e->errorType==='recovery_lease_lost';}check($blocked,'Superseded worker retained mutation or billing authority');Context::enter($id,'old-worker');}
 $current=S::table('request')->where('request_id',$id)->find();check($current['lease_token']==='new-worker'&&$current['status']==='running'&&(float)$current['budget_user']===65.&&(float)$current['actual_user']===0.,'Stale worker changed authoritative state');
 Context::enter($id,'new-worker');S::table('request')->where('request_id',$id)->update(['lease_expire'=>time()-1]);$blocked=false;try{Admission::finish($id,['status'=>'success']);}catch(app\common\service\open_platform\OpenException $e){$blocked=$e->errorType==='recovery_lease_lost';}check($blocked,'Expired worker changed state before takeover');
 Context::leave();Admission::finish($id,['error_message'=>'normal request context']);check(S::table('request')->where('request_id',$id)->value('error_message')==='normal request context','Ordinary request context changed');
 echo "PASS superseded and expired workers cannot change state, release newer leases or settle; identical writes and normal requests preserved\n";
}finally{Context::leave();Db::rollback();}
