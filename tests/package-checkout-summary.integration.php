<?php
require __DIR__.'/Support/OemDisposable.php';
require __DIR__.'/Support/OemFixtures.php';
require __DIR__.'/Support/VirtualPayFixture.php';
use think\facade\Db;
use app\common\service\membership\MembershipService;
use app\common\service\promotion\PackagePricingService;
use app\common\logic\PaymentLogic;

Db::startTrans();
try {
    $f=virtualFixture();
    $plan=MembershipService::savePlan($f['tenant'],['name'=>'已选高级会员','description'=>'购买时的套餐简介','duration_months'=>1,'monthly_price'=>'39.90','yearly_price'=>'399.00','monthly_bonus_points'=>'300.00','features'=>['购买时的会员权益']]);
    $app=['code'=>'checkout_summary_'.$f['tenant'],'name'=>'购买时的应用名称'];
    Db::name('app')->insert($app);
    Db::name('membership_plan_app')->insert(['tenant_id'=>$f['tenant'],'plan_id'=>$plan->id,'app_code'=>$app['code']]);
    $order=PackagePricingService::create($f['tenant'],$f['user'],1,'membership',(int)$plan->id);
    Db::name('membership_plan')->where('id',$plan->id)->update(['name'=>'修改后的套餐','description'=>'修改后的简介','duration_months'=>12,'monthly_bonus_points'=>'999.00','features'=>'["修改后的权益"]']);
    Db::name('app')->where('code',$app['code'])->update(['name'=>'修改后的应用名称']);
    foreach ([1,3] as $terminal) {
        $result=PaymentLogic::getPayWay($f['user'],$terminal,['tenant_id'=>$f['tenant'],'from'=>'membership','order_id'=>$order['order_id'],'platform'=>'ohos']);
        oemCheck(is_array($result),'both mini-program and H5 can review the order');
        $s=$result['summary'];
        oemCheck($s['title']==='已选高级会员' && $s['duration_months']===1 && $s['bonus_points']==='300.00','review retains purchased membership');
        oemCheck($s['features']===['购买时的会员权益'] && $s['description']==='购买时的套餐简介' && $s['app_names']===[$app['name']],'review retains snapshotted copy and application names');
        if ($terminal===1) oemCheck($result['lists'][0]['name']==='微信支付' && $result['lists'][0]['extra']==='','customer sees the familiar payment method');
    }
    oemCheck(PaymentLogic::getPayWay($f['user']+1,1,['tenant_id'=>$f['tenant'],'from'=>'membership','order_id'=>$order['order_id']])===false,'order summary preserves owner isolation');
    echo "PASS actual mini-program/H5 payment summary, frozen membership/app names and owner isolation\n";
} finally { Db::rollback(); }
