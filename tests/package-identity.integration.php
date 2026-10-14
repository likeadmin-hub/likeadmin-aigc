<?php
require __DIR__.'/Support/OemDisposable.php';require __DIR__.'/Support/OemFixtures.php';require __DIR__.'/Support/VirtualPayFixture.php';
use think\facade\Db;
use app\common\service\promotion\PackageIdentityService as Identity;
use app\common\service\promotion\PackagePricingService as Price;
Db::startTrans();try{
 $f=virtualFixture();$target=Db::name('user')->insertGetId(['tenant_id'=>$f['tenant'],'sn'=>random_int(10000000,99999999),'account'=>'merge-target-'.bin2hex(random_bytes(4)),'create_time'=>time(),'user_money'=>'0']);
 oemReject(fn()=>Identity::merge($f['tenant'],$f['user'],$target),'pending shadow cannot lose payment identity');
 Db::name('tenant_package_price_snapshot')->where(['tenant_id'=>$f['tenant'],'order_id'=>$f['order']['order_id']])->update(['status'=>'refunded']);Db::name('tenant_package_discount_claim')->where(['tenant_id'=>$f['tenant'],'user_id'=>$f['user']])->update(['status'=>'used','consumed_at'=>time()]);
 Identity::merge($f['tenant'],$f['user'],$target);Identity::merge($f['tenant'],$f['user'],$target);oemCheck(Price::quote($f['tenant'],$target,'recharge',$f['package'])['reason']==='used','refunded once-use survives merge and repeated merge');
 oemReject(fn()=>Identity::merge($f['tenant']+100000,$f['user'],$target),'cross tenant identity cannot merge');
 echo "PASS pending account identity retention and merged refunded discount remains used\n";
}finally{Db::rollback();}
