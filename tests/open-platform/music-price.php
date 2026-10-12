<?php
/** Native settlement only, rolled back locally; no provider or storage writes. */
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
use think\facade\Db;
use app\common\service\open_platform\OpenStore as S;
use app\common\service\open_platform\OpenManagement as M;
use app\common\service\open_platform\OpenToolPricing as P;
use app\common\service\open_platform\OpenCallContext as C;
use app\common\service\open_platform\OpenRecovery;
use app\common\service\app\aigc_music\AigcMusicChannelService as Channels;
use app\common\service\app\aigc_music\AigcMusicService as Music;
use app\common\model\app\aigc_music\AigcMusicTask as Task;
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
$db=config('database.connections.mysql');check(PHP_SAPI==='cli'&&$db['hostname']==='127.0.0.1'&&$db['database']==='z_cn','Local rollback fixture only');
$before=Db::name('user')->where(['tenant_id'=>1,'id'=>1])->value('user_money');$tenantBefore=Db::name('tenant')->where('id',1)->value('point_balance');
Db::startTrans();try{
 foreach([0,1] as $t){$settings=S::settings($t);$settings['apps'][]='aigc_music';M::persistSettings($t,true,$settings);}
 $req=request();$req->tenantId=1;$req->userId=1;
 $budget=P::musicBudget($req,['duration'=>15]);$selection=Channels::resolveSelection(1,['duration'=>15]);
 $rid='req_fixture_'.bin2hex(random_bytes(16));$base=['tenant_id'=>1,'user_id'=>1,'key_id'=>0,'kind'=>'tool','app_code'=>'aigc_music','method'=>'POST','path'=>'/api/v1/tools/aigc_music/tasks','status'=>'running','billing_status'=>'pending','active'=>1,'budget_user'=>$budget['user'],'budget_tenant'=>$budget['tenant'],'actual_user'=>0,'actual_tenant'=>0,'consumption_ids'=>'[]','snapshot'=>S::encode($budget['snapshot']),'response_blob'=>'','payload_hash'=>hash('sha256','pricing fixture')]+S::timestamp();S::table('request')->insert($base+['request_id'=>$rid]);
 C::enter($rid);$frozen=P::forRequest('aigc_music',1,1);check($frozen&&$frozen['quote']['user_charge_points']==$budget['user'],'Accepted selection lost');
 $task=Task::create(['tenant_id'=>1,'user_id'=>1,'app_code'=>'aigc_music','action'=>'music_generation','title'=>'fixture','prompt'=>'synthetic pricing fixture','lyrics'=>'','channel'=>$selection['channel']['code'],'quality'=>$selection['spec']['quality'],'ratio'=>$selection['spec']['ratio'],'duration'=>15,'quantity'=>$budget['snapshot']['quote']['quantity'],'tenant_cost_points'=>$budget['tenant'],'user_charge_points'=>$budget['user'],'provider'=>$selection['channel']['provider'],'model'=>$selection['channel']['model'],'idempotency_key'=>bin2hex(random_bytes(16)),'billing_status'=>'prechecked','status'=>'running','delete_time'=>0,'create_time'=>time(),'update_time'=>time()]);C::leave();
 check(P::forTask('aigc_music',1,2,$task['id'])===null&&P::forTask('aigc_video',1,1,$task['id'])===null,'Cross-user/tool selection leaked');
 Db::name('aigc_music_channel_spec')->where(['tenant_id'=>0,'id'=>$selection['spec']['id']])->update(['platform_unit_cost'=>999,'tenant_unit_price'=>999]);
 Db::name('aigc_music_channel_spec')->where(['tenant_id'=>1,'channel_code'=>$selection['channel']['code'],'quality'=>$selection['spec']['quality']])->update(['tenant_unit_price'=>999]);
 $changed=Channels::resolveSelection(1,['channel'=>$selection['channel']['code'],'quality'=>$selection['spec']['quality']]);check($changed['spec']['platform_unit_cost']==999,'Fixture did not change live price');
 $fresh=P::forTask('aigc_music',1,1,$task['id']);check($fresh['selection']['spec']===$selection['spec'],'Worker task selection changed after price edit');
 $queryRid='req_fixture_'.bin2hex(random_bytes(16));S::table('request')->insert(array_replace($base,['request_id'=>$queryRid,'method'=>'GET','path'=>'/api/v1/tools/aigc_music/tasks/'.$task['id'],'snapshot'=>'{}','budget_user'=>0,'budget_tenant'=>0,'billing_status'=>'none']));C::enter($queryRid);
 $finish=new ReflectionMethod(Music::class,'finishTaskWithItems');$items=[['audio_uri'=>'uploads/opf-pricing-fixture.mp3','storage_scope'=>'tenant','storage_engine'=>'local','storage_domain'=>'','duration'=>15,'mime_type'=>'audio/mpeg','file_size'=>1]];
 $finish->invoke(null,$task,$changed,$items);$finish->invoke(null,$task,$changed,$items);C::leave();
 $origin=S::table('request')->where('request_id',$rid)->find();$query=S::table('request')->where('request_id',$queryRid)->find();check((float)$origin['actual_user']===$budget['user']&&(float)$query['actual_user']===0.,'Native settlement attributed to free query or repriced');
 $billing=Db::name('aigc_music_billing')->where(['tenant_id'=>1,'task_id'=>$task['id']])->select()->toArray();check(count($billing)===1&&$billing[0]['tenant_unit_price']==$selection['spec']['tenant_unit_price']&&$billing[0]['platform_unit_cost']==$selection['spec']['platform_unit_cost'],'Native receipt uses mutable or duplicate billing');
 check(count(M::detail(1,1,$rid)['point_logs'])===1,'Repeated completion charged twice or lost independent ledger');
 app\common\service\open_platform\OpenVerification::assertLedger($origin);
 echo "PASS frozen music selection and native bill survive price edits; worker lookup is owned, free query cannot absorb charge, repeat completion is idempotent\n";
}finally{C::leave();Db::rollback();}
check(Db::name('user')->where(['tenant_id'=>1,'id'=>1])->value('user_money')===$before&&Db::name('tenant')->where('id',1)->value('point_balance')===$tenantBefore,'Rollback changed wallets');
