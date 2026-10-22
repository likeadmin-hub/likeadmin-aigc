<?php
require 'vendor/autoload.php';(new think\App())->initialize();
use think\facade\Db;
use app\common\service\membership\MembershipService as M;
use app\common\service\app\AppAccessService as A;
use app\common\model\membership\MembershipPlan;
$c=config('database.connections.mysql');if($c['hostname']!=='127.0.0.1'||$c['database']!=='z_cn')throw new RuntimeException('Local DB only');
$n=0;function verify($v,$message){global $n;if(!$v)throw new RuntimeException($message);$n++;}
Db::startTrans();
try{
 $t=987654320;$u=987654319;$now=time();
 Db::name('tenant')->insert(['id'=>$t,'sn'=>'member_access_fixture','name'=>'membership fixture','create_time'=>$now,'tactics'=>0]);
 Db::name('user')->insert(['id'=>$u,'tenant_id'=>$t,'sn'=>$u,'account'=>'member_access_fixture','nickname'=>'membership fixture','create_time'=>$now]);
 $p=MembershipPlan::create(['tenant_id'=>$t,'name'=>'delete fixture','monthly_price'=>10,'yearly_price'=>10,'status'=>1,'features'=>[],'create_time'=>$now]);$id=(int)$p['id'];
 Db::name('membership_plan_app')->insert(['tenant_id'=>$t,'plan_id'=>$id,'app_code'=>'aigc_pic_lipsync']);
 $order=Db::name('membership_order')->insertGetId(['tenant_id'=>$t,'user_id'=>$u,'plan_id'=>$id,'order_sn'=>'member_access_fixture','pay_status'=>1,'create_time'=>$now]);
 Db::name('user_membership')->insert(['tenant_id'=>$t,'user_id'=>$u,'plan_id'=>$id,'plan_name'=>'delete fixture','app_codes'=>json_encode(['aigc_pic_lipsync']),'features'=>'[]','start_time'=>$now,'expire_time'=>$now+3600,'status'=>1]);
 verify(M::userCanUseApp($t,$u,'aigc_pic_lipsync'),'entitled user allowed');
 verify(A::assertTenantCanUse($t,'aigc_pic_lipsync',0)->getData()['data']['need_login']===1,'anonymous submit still denied');
 M::deletePlan($t,$id);
 verify(MembershipPlan::where(['tenant_id'=>$t,'id'=>$id])->count()===0,'used plan disappears after delete rather than only disabling');
 verify((int)Db::name('membership_plan')->where('id',$id)->value('delete_time')>0,'delete is recoverable soft deletion');
 verify(Db::name('membership_order')->where('id',$order)->count()===1,'history order preserved');
 verify(M::status($t,$u)['member_apps']===['aigc_pic_lipsync'],'paid entitlement snapshot preserved');
 verify(M::detail($t,$id)===[] && M::plans($t,false)===[],'deleted plan excluded from detail and all status lists');
 verify(!M::appRequiresMembership($t,'aigc_pic_lipsync'),'deleted plan associations do not gate app');
 try{M::deletePlan($t+1,$id);throw new RuntimeException('tenant delete unexpectedly passed');}catch(RuntimeException $e){verify($e->getMessage()==='套餐不存在','cross tenant deletion denied');}
 // Reuse a second plan to test the availability/membership boundary.
 $p=MembershipPlan::create(['tenant_id'=>$t,'name'=>'access fixture','status'=>1,'features'=>[]]);
 Db::name('membership_plan_app')->insert(['tenant_id'=>$t,'plan_id'=>$p['id'],'app_code'=>'aigc_pic_lipsync']);
 Db::name('user_membership')->where(['tenant_id'=>$t,'user_id'=>$u])->update(['app_codes'=>'[]']);
 verify(A::assertTenantAvailable($t,'aigc_pic_lipsync')===null,'tenant availability ignores member identity');
 $denied=A::assertTenantCanUse($t,'aigc_pic_lipsync',$u)->getData();
 verify($denied['data']['need_membership']===1 && str_contains($denied['msg'],'订阅'),'member denial gives subscription message');
 verify(A::assertTenantAvailable($t,'nonexistent_fixture')!==null,'unavailable tenant app still denied');
 $nextCalls=0;
 $request=new class($t,$u){public function __construct(public int $tenantId,public int $userId){} public string $verb='GET';public string $ctl='app.aigc_pic_lipsync.config';public function controller(){return $this->ctl;}public function action(){return 'detail';}public function method(){return $this->verb;}};
 $middleware=new app\api\http\middleware\AppAccessMiddleware();$next=function()use(&$nextCalls){$nextCalls++;return 'render';};
 verify($middleware->handle($request,$next)==='render','unsubscribed user can read safe config');
 $request->verb='POST';$denied=$middleware->handle($request,$next)->getData();
 verify(($denied['data']['need_membership']??0)===1 && $nextCalls===1,'POST config and submit remain protected');
 $request->verb='GET';$request->ctl='app.aigc_pic_lipsync.task';
 verify($middleware->handle($request,$next) instanceof \think\Response && $nextCalls===1,'private GET history remains protected');
 $access=M::appAccess($t,$u,'aigc_pic_lipsync');verify($access['available']===1 && $access['allowed']===0,'app access endpoint distinguishes subscription from availability');
 verify(M::appAccess($t,$u,'nonexistent_fixture')['available']===0,'app access endpoint reports unavailable app');
 echo "PASS $n membership delete / entitlement / access assertions (rolled back)\n";
}finally{Db::rollback();}
