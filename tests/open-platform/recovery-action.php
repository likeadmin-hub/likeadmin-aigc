<?php
/** Preview/confirmation fault fixtures: no upstream, worker run or wallet writes. */
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
use think\facade\Db;
use app\common\service\open_platform\OpenStore as S;
use app\common\service\open_platform\OpenManagement as M;
use app\common\service\open_platform\OpenRecoveryAction as A;
use app\common\service\open_platform\OpenException;
use app\common\service\open_platform\OpenVault;
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
function denied(callable $fn,string $why){$ok=false;try{$fn();}catch(OpenException){$ok=true;}check($ok,$why);}
$db=config('database.connections.mysql');check(PHP_SAPI==='cli'&&$db['hostname']==='127.0.0.1'&&$db['database']==='z_cn','Local rollback fixtures only');
$wallet=Db::name('user')->where(['tenant_id'=>1,'id'=>1])->value('user_money');$tenantWallet=Db::name('tenant')->where('id',1)->value('point_balance');
Db::startTrans();try{
 $base=['tenant_id'=>123456,'user_id'=>456789,'key_id'=>0,'kind'=>'tool','app_code'=>'aigc_music','method'=>'POST','path'=>'/api/v1/tools/aigc_music/tasks','tool_task_id'=>'123','status'=>'running','billing_status'=>'pending','active'=>1,'consumption_ids'=>'[]','snapshot'=>S::encode(['_execution_version'=>2,'dispatch_started'=>true,'private'=>'hidden receipt']),'payload_hash'=>hash('sha256','fixture'),'response_blob'=>'','budget_user'=>65,'budget_tenant'=>5,'actual_user'=>0,'lease_expire'=>0,'next_run_time'=>time()+3600]+S::timestamp();
 $create=static function($override=[])use($base){$rid='req_action_fixture_'.bin2hex(random_bytes(12));S::table('request')->insert(array_replace($base,$override,['request_id'=>$rid]));return $rid;};
 $rid=$create();$before=S::table('request')->where('request_id',$rid)->find();$preview=M::recoveryPreview(123456,$rid);check($preview['resume']['available']&&$preview['resume']['preview_token']!==''&&S::table('request')->where('request_id',$rid)->find()===$before,'Offer missing or wrote state');
 $token=$preview['resume']['preview_token'];denied(fn()=>A::resume(123457,$rid,$token,'tenant_admin',1),'Cross tenant confirmation');denied(fn()=>A::resume(123456,$rid,$token,'user',1),'User granted admin recovery');denied(fn()=>A::resume(123456,$rid,$token,'platform_admin',0),'No actor granted recovery');denied(fn()=>A::resume(123456,$rid,$token.'x','tenant_admin',1),'Tampered preview');
 $expired=S::json(OpenVault::open($token));$expired['expires_at']=time()-1;denied(fn()=>A::resume(123456,$rid,OpenVault::seal(S::encode($expired)),'tenant_admin',1),'Expired preview');
 S::table('request')->where('request_id',$rid)->update(['budget_user'=>64]);denied(fn()=>A::resume(123456,$rid,$token,'tenant_admin',1),'Changed impact accepted old proof');
 $preview=M::recoveryPreview(123456,$rid);$token=$preview['resume']['preview_token'];$result=A::resume(123456,$rid,$token,'tenant_admin',7);$after=S::table('request')->where('request_id',$rid)->find();check($result['status']==='tracking_scheduled'&&!$result['new_submission']&&$result['charged_now']===0,'Confirmation submitted or charged');check((int)$after['next_run_time']<=time()&&(int)$after['next_run_time']>0&&$after['status']===$before['status']&&(float)$after['actual_user']===0.,'Confirmation changed execution/bill');check(S::json($after['snapshot'])['manual_resume']['admin_id']===7,'Actor audit missing');denied(fn()=>A::resume(123456,$rid,$token,'tenant_admin',7),'Same confirmation replayed');
 foreach([['tool_task_id'=>'','status'=>'pending_confirmation'],['lease_expire'=>time()+60],['active'=>0,'billing_status'=>'settled'],['active'=>1,'billing_status'=>'refunded'],['snapshot'=>'{}'],['method'=>'GET','billing_status'=>'none']] as $override){$id=$create($override);$p=M::recoveryPreview(123456,$id);check(!$p['resume']['available']&&$p['resume']['preview_token']==='','Unknown, leased, closed or historical request resumed');denied(fn()=>A::resume(123456,$id,$token,'platform_admin',7),'Proof applied to another request');}
 foreach(['platform','tenant'] as $scene){$root=dirname(__DIR__,2).'/app/apps/open_platform/';$perms=S::json(file_get_contents($root.'permissions/'.$scene.'.json'));check(count(array_filter($perms,fn($p)=>$p['api_path']==='app.open_platform.management/resumeTracking'&&$p['api_method']==='POST'))===1,'Missing explicit '.$scene.' permission');}
 $granted=new ReflectionMethod(app\common\service\open_platform\OpenAdminRecoveryPermission::class,'granted');
 foreach(['app.open_platform.management/resumeTracking','app.openPlatform.Management/resumeTracking'] as $uri)check($granted->invoke(null,[$uri]),'Authorized non-root role rejected after framework URI normalization');
 foreach([[],['app.open_platform.management/recoveryPreview'],['app.open_platform.management/requests']] as $uris)check(!$granted->invoke(null,$uris),'Read-only role gained recovery write permission');
 echo "PASS confirmed recovery only schedules original tracking; stale/tampered/expired proof, cross tenant, missing actor, leases, unknown submissions and closed historical bills rejected; actor audited, no charge\n";
}finally{Db::rollback();}
check(Db::name('user')->where(['tenant_id'=>1,'id'=>1])->value('user_money')===$wallet&&Db::name('tenant')->where('id',1)->value('point_balance')===$tenantWallet,'Rollback changed wallets');
