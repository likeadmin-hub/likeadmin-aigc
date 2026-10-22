<?php
// Requires the approved migration first. All business fixture writes roll back.
require __DIR__.'/bootstrap.php';
use think\facade\Db;
use app\common\service\redeem\RedeemCardService as Cards;
use app\common\service\redeem\RedeemCardPolicy as Policy;
use app\common\enum\user\AccountLogEnum;
Db::startTrans();
try{
 $u=redeemFixtureUser();$other=redeemFixtureUser();$foreign=redeemFixtureUser(2);
 $make=fn($extra=[])=>Cards::generate(1,1,array_replace(['name'=>'rollback batch','type'=>'points','quantity'=>1,'points'=>'12.50','expire_time'=>0],$extra));
 $g=$make();$code=$g['codes'][0]['code'];$id=$g['codes'][0]['id'];
 redeemCheck(strlen(Policy::normalizeCode($code))===32,'128-bit code');
 redeemCheck(Db::name('redeem_card')->where('id',$id)->value('code_hash')===Policy::hash($code),'hash stored');
 $lists=Cards::listing(1,'cards',[]);redeemCheck(!str_contains(json_encode($lists),$code)&&!str_contains(json_encode($lists),Policy::hash($code)),'list never exposes code or lookup hash');
 redeemReject(fn()=>Cards::redeem(1,0,$code),'unauthenticated');redeemReject(fn()=>Cards::redeem(2,$foreign,$code),'invalid');
 $first=Cards::redeem(1,$u,$code);$again=Cards::redeem(1,$u,strtolower(str_replace('-',' ',$code)));
 redeemCheck($first['record_id']===$again['record_id']&&$again['replayed']===true,'same user replay returns immutable success');
 redeemCheck(Db::name('user')->where('id',$u)->value('user_money')==='12.50','credited exactly once');
 redeemCheck(Db::name('user_account_log')->where(['tenant_id'=>1,'user_id'=>$u,'source_sn'=>'redeem_card_1_'.$id,'change_type'=>AccountLogEnum::UM_INC_REDEEM_CARD])->count()===1,'one dedicated points ledger');
 redeemReject(fn()=>Cards::redeem(1,$other,$code),'used');
 $g=$make();Cards::disable(1,['id'=>$g['codes'][0]['id']]);redeemReject(fn()=>Cards::redeem(1,$u,$g['codes'][0]['code']),'disabled');
 $g=$make();Cards::disable(1,['batch_id'=>$g['batch']['id']]);redeemReject(fn()=>Cards::redeem(1,$u,$g['codes'][0]['code']),'disabled');
 $g=$make();Db::name('redeem_card_batch')->where('id',$g['batch']['id'])->update(['expire_time'=>time()-1]);redeemReject(fn()=>Cards::redeem(1,$u,$g['codes'][0]['code']),'expired');
 redeemReject(fn()=>Cards::disable(2,['id'=>$g['codes'][0]['id']]),'invalid');
 redeemReject(fn()=>$make(['quantity'=>201]),'invalid');redeemReject(fn()=>$make(['points'=>'1e9']),'invalid');
 $p=redeemFixturePlan();$p2=redeemFixturePlan(1,'其他套餐');
 $m=fn($plan)=>$make(['type'=>'membership','plan_id'=>$plan,'duration_months'=>1]);
 // Permanent free membership must start a finite paid-card period from now.
 Db::name('user_membership')->insert(['tenant_id'=>1,'user_id'=>$other,'plan_id'=>0,'plan_name'=>'免费会员','app_codes'=>'[]','features'=>'[]','start_time'=>time(),'expire_time'=>4294967295,'status'=>1,'source_order_sn'=>'register_free_1_'.$other,'create_time'=>time(),'update_time'=>time()]);
 $g=$m($p);$r=Cards::redeem(1,$other,$g['codes'][0]['code']);redeemCheck($r['member_expire_time']>time()&&$r['member_expire_time']<time()+40*86400,'free permanent sentinel not extended');
 $before=$r['member_expire_time'];$g=$m($p);$r=Cards::redeem(1,$other,$g['codes'][0]['code']);redeemCheck($r['member_expire_time']===(int)strtotime('+1 months',$before),'same plan extends existing expiry');
 $balance=Db::name('user')->where('id',$other)->value('user_money');Cards::redeem(1,$other,$g['codes'][0]['code']);redeemCheck(Db::name('user')->where('id',$other)->value('user_money')===$balance,'member gift replay exactly once');
 redeemCheck($balance==='10.00','each membership card grants its snapshot bonus once');
 $g=$m($p2);redeemReject(fn()=>Cards::redeem(1,$other,$g['codes'][0]['code']),'membership_conflict');redeemCheck(Db::name('redeem_card')->where('id',$g['codes'][0]['id'])->value('status')==='unused','conflict does not consume card');
 // Generated rights survive plan rename, price/bonus changes, disable and deletion.
 $g=$m($p2);Db::name('membership_plan')->where('id',$p2)->update(['name'=>'changed after issue','monthly_bonus_points'=>'999','status'=>0,'delete_time'=>time()]);$r=Cards::redeem(1,$u,$g['codes'][0]['code']);
 redeemCheck($r['plan_name']==='其他套餐','issued plan name is immutable');redeemCheck(Db::name('user')->where('id',$u)->value('user_money')==='17.50','issued gift snapshot is immutable');
 $records=Cards::listing(1,'records',['type'=>'membership'], $u);redeemCheck($records['count']===1&&(int)$records['lists'][0]['user_id']===$u,'private records retain type and owner filtering');
 redeemCheck(Cards::listing(2,'records',['batch_id'=>$g['batch']['id']])['count']===0,'other tenant cannot list records');
 // Same-plan default-free sentinel is not a renewal base for a finite card.
 $freePlan=redeemFixturePlan(1,'免费会员');Db::name('membership_plan')->where('id',$freePlan)->update(['monthly_price'=>'0.00','yearly_price'=>'0.00','monthly_bonus_points'=>'0.00']);
 $sameFree=redeemFixtureUser();Db::name('user_membership')->insert(['tenant_id'=>1,'user_id'=>$sameFree,'plan_id'=>$freePlan,'plan_name'=>'免费会员','app_codes'=>'[]','features'=>'[]','start_time'=>time(),'expire_time'=>4294967295,'status'=>1,'source_order_sn'=>'register_free_1_'.$sameFree,'create_time'=>time(),'update_time'=>time()]);
 $g=$m($freePlan);$r=Cards::redeem(1,$sameFree,$g['codes'][0]['code']);redeemCheck($r['member_expire_time']>time()&&$r['member_expire_time']<time()+40*86400,'same-plan default-free card starts now without inheriting sentinel');
 // A paid permanent entitlement is never silently replaced by a finite card.
 $permanent=redeemFixtureUser();Db::name('user_membership')->insert(['tenant_id'=>1,'user_id'=>$permanent,'plan_id'=>$p,'plan_name'=>'永久付费会员','app_codes'=>'[]','features'=>'[]','start_time'=>time(),'expire_time'=>4294967295,'status'=>1,'source_order_sn'=>'paid_permanent_fixture','create_time'=>time(),'update_time'=>time()]);
 $g=$m($p);redeemReject(fn()=>Cards::redeem(1,$permanent,$g['codes'][0]['code']),'membership_conflict');
 // Exercise the actual admin operation-log listener: HTTP response stays intact,
 // stored request and result never retain a generated code or lookup hash.
 $request=request();$request->tenantId=1;$request->adminInfo=['admin_id'=>1,'name'=>'fixture','account'=>'fixture'];
 $request->setController('finance.RedeemCard');$request->setAction('generate');$request->setMethod('POST');$request->withGet([]);$request->withPost(['code'=>$code]);
 $request->controllerObject=new class { public function isNotNeedLogin(){return false;} /** 生成卡密 */ public function generate(){} };
 $response=\think\Response::create(['code'=>1,'data'=>['codes'=>[['code'=>$code,'code_hash'=>Policy::hash($code)]]]],'json');
 $beforeLogId=(int)\app\common\model\OperationLog::max('id');
 $saved=(new \app\tenantapi\listener\OperationLog())->handle($response);
 $log=\app\common\model\OperationLog::where('id','>',$beforeLogId)->order('id','desc')->findOrEmpty();
 redeemCheck($saved!==false&&!$log->isEmpty()&&!str_contains((string)$log['params'],$code)&&!str_contains((string)$log['result'],$code)&&!str_contains((string)$log['result'],Policy::hash($code)),'actual operation log redacts request/result');
 redeemCheck(str_contains($response->getContent(),$code),'one-time HTTP response remains unchanged');
 // Entry limits count malformed guesses; failures do not roll back the counters.
 for($i=0;$i<10;$i++)Cards::throttle(1,$u,'203.0.113.66');
 redeemReject(fn()=>Cards::throttle(1,$u,'203.0.113.66'),'rate_limited');
 echo 'PASS redeem-card integration: '.($GLOBALS['redeem_checks']??0)." checks; fixture transaction will roll back\n";
}finally{Db::rollback();}
