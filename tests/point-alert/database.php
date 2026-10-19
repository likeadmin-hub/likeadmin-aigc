<?php
require dirname(__DIR__,2).'/vendor/autoload.php';
(new think\App())->initialize();
use think\facade\Db;
use app\common\service\point_alert\AlertStore as S;
use app\common\service\point_alert\AlertService as A;
function ok($v,$m){if(!$v)throw new RuntimeException($m);}
$c=config('database.connections.mysql');if($c['hostname']!=='127.0.0.1'||$c['database']!=='z_cn')throw new RuntimeException('Local DB only');
$t=987654320;$u=987654319;$now=time();
Db::startTrans();
try{
 Db::name('tenant')->insert(['id'=>$t,'sn'=>'pointfixture','name'=>'fixture','point_balance'=>120,'create_time'=>$now,'tactics'=>0]);
 Db::name('user')->insert(['id'=>$u,'tenant_id'=>$t,'sn'=>$u,'account'=>'alert_fixture','nickname'=>'fixture','user_money'=>120,'create_time'=>$now]);
 ok(!S::settings(0)['rules']['enabled'],'defaults disabled');
 S::saveRules(0,['enabled'=>true,'thresholds'=>['1000','200','50'],'min_interval_minutes'=>1],0);
 S::saveRules($t,['enabled'=>true,'thresholds'=>['1000','200','50'],'min_interval_minutes'=>1],0);
 A::check($t,0,$now);A::check($t,0,$now);ok(S::table('round')->where(['tenant_id'=>$t,'user_id'=>0])->count()===1,'duplicate tenant admission');
 A::check($t,$u,$now);ok(S::table('round')->where(['owner_id'=>$t,'user_id'=>$u])->count()===1,'user owner admission');
 ok(S::records(0,[])['count']===1&&S::records($t,[])['count']===1,'owner records isolation');
 $sent=[];A::dispatch(30,function($d,$r)use(&$sent){$sent[]=$d['id'];return ['status'=>'success','result'=>'fixture'];});
 ok(count($sent)===2,'dispatch one per admitted account');A::dispatch(30,fn()=>throw new RuntimeException('duplicate delivery'));
 ok(count(S::inbox($t,$u))===1&&count(S::inbox($t,$u+1))===0,'private inbox isolation');
 $id=S::inbox($t,$u)[0]['id'];ok(S::readInbox($t,$u+1,$id)===false,'cross user read blocked');ok(S::readInbox($t,$u,$id),'own read');
 Db::name('user')->where('id',$u)->update(['user_money'=>40]);A::check($t,$u,$now+61);
 Db::name('user')->where('id',$u)->update(['user_money'=>2000]);A::dispatch(30,fn()=>throw new RuntimeException('recovered sent'));
 ok(S::table('round')->where(['tenant_id'=>$t,'user_id'=>$u])->order('id','desc')->value('status')==='cancelled','recovered pending cancelled');
 $preview=app\common\service\point_alert\AlertAdmin::preview($t,9,[]);
 $denied=false;try{app\common\service\point_alert\AlertAdmin::enqueue($t,10,$preview['token']);}catch(RuntimeException $e){$denied=true;}ok($denied,'preview token foreign admin');
 $denied=false;try{S::detail($t+1,$id);}catch(RuntimeException $e){$denied=true;}ok($denied,'foreign record detail');
 $denied=false;try{S::saveRules($t,['enabled'=>false],0);}catch(RuntimeException $e){$denied=true;}ok($denied,'stale config revision');
 // Coalesced lower levels must not create a second identical round.
 Db::name('tenant')->where('id',$t)->update(['point_balance'=>40]);
 A::check($t,0,$now+61);Db::name('tenant')->where('id',$t)->update(['point_balance'=>0]);
 A::dispatch(30,fn()=>['status'=>'success','result'=>'fixture']);
 ok(!A::check($t,0,$now+122)['send'],'coalesced level covered');
 Db::execute('CREATE TEMPORARY TABLE la_user_alertsplit LIKE la_user');
 Db::execute('CREATE TEMPORARY TABLE la_tenant_config_alertsplit LIKE la_tenant_config');
 Db::name('tenant')->insert(['id'=>$t-2,'sn'=>'alertsplit','name'=>'split','point_balance'=>120,'create_time'=>$now,'tactics'=>1]);
 Db::name('user_alertsplit')->insert(['id'=>$u,'tenant_id'=>$t-2,'sn'=>$u-2,'account'=>'split_fixture','nickname'=>'split fixture','user_money'=>7,'create_time'=>$now]);
 ok(S::account($t-2,$u)['balance']==='7.00','split account resolved');
 ok(S::account($t,$u)['balance']==='2000.00','shared account not borrowed');
 Db::execute('DROP TEMPORARY TABLE la_user_alertsplit');Db::execute('DROP TEMPORARY TABLE la_tenant_config_alertsplit');
 // New channels at the coalesced lower level must be admitted in the same round.
 $coalesced=S::settings($t)['rules'];$coalesced['level_channels']['50.00']=['in_app','sms'];
 S::table('setting')->where('owner_id',$t)->update(['config'=>S::encode($coalesced)]);
 Db::name('user')->insert(['id'=>$u+2,'tenant_id'=>$t,'sn'=>$u+2,'account'=>'coalesce_fixture','nickname'=>'coalesce','user_money'=>120,'mobile'=>'13800138000','create_time'=>$now]);
 A::check($t,$u+2,$now);Db::name('user')->where('id',$u+2)->update(['user_money'=>40]);
 $channels=[];$fake=function($d)use(&$channels){$channels[]=$d['channel'];return ['status'=>'success','result'=>'fixture'];};
 A::dispatch(30,$fake);A::dispatch(30,$fake);
 ok(count(array_filter($channels,fn($c)=>$c==='sms'))===1,'coalesced urgent SMS delivered once');
 // Rollback marker must be in same transaction as account logs.
 Db::startTrans();A::touch($t,$u);Db::rollback();ok(!S::table('dirty')->where(['tenant_id'=>$t,'user_id'=>$u])->find(),'rolled back outbox marker');
 echo "PASS point-alert database isolation, caps and recovery\n";
} finally { Db::rollback(); }
