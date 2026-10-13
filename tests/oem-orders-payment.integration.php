<?php
declare(strict_types=1);
require __DIR__.'/Support/OemDisposable.php';
require __DIR__.'/Support/OemFixtures.php';
use think\facade\Db;
use app\common\service\brand\TenantBrandService as Brand;
use app\common\service\brand\BrandWorkflowService as Flow;
use app\common\service\brand\BrandPaymentService as Payment;
Db::startTrans();
try {
    $f=oemFixture();
    $created=Brand::createBrandOrder($f['seller'],$f['user'],5,$f['params']);
    $id=$created['order_id'];
    oemCheck($created['order_amount']==='31.01','server customer amount');
    $order=Db::name('tenant_brand_order')->where('id',$id)->find();
    oemCheck((int)$order['reserve_status']===0 && (int)$order['flow_version']===2,'zero quota still accepts unpaid application');
    oemReject(fn()=>Flow::owned($f['seller'],$f['user']+1,$id),'cross-user order accepted');
    oemReject(fn()=>Flow::owned($f['seller']+1,$f['user'],$id),'cross-seller order accepted');
    oemReject(fn()=>Brand::createBrandOrder($f['seller'],$f['user'],5,array_replace($f['params'],['target_tenant_id'=>1])),'unowned renewal accepted');
    $paySn='oem-test-payment-'.$id;
    $context=json_encode(['scope'=>'tenant','tenant_id'=>$f['seller'],'merchant_id'=>'merchant-A','app_id'=>'app-A']);
    Db::name('tenant_brand_payment_intent')->insert(['order_from'=>Brand::FROM_ORDER,'order_id'=>$id,'tenant_id'=>$f['seller'],'pay_sn'=>$paySn,'channel'=>2,'merchant_id'=>'merchant-A','amount_cents'=>3101,'context'=>$context,'expires_at'=>time()+1800]);
    $receipt=['pay_sn'=>$paySn,'channel'=>2,'merchant_id'=>'merchant-A','app_id'=>'app-A','transaction_id'=>'trade-A','amount_cents'=>3101,'currency'=>'CNY','status'=>'success'];
    foreach (['merchant_id'=>'other','amount_cents'=>3100,'currency'=>'USD','app_id'=>'other','transaction_id'=>'','status'=>'pending'] as $key=>$value) {
        oemReject(fn()=>Payment::recordVerified(array_replace($receipt,[$key=>$value])),'invalid receipt accepted: '.$key);
    }
    oemCheck(Db::name('tenant_brand_payment')->where('order_id',$id)->count()===0,'invalid receipts cannot create money records');
    Payment::recordVerified($receipt);
    Payment::recordVerified($receipt);
    $order=Db::name('tenant_brand_order')->where('id',$id)->find();
    oemCheck((int)$order['pay_status']===1 && $order['workflow_state']==='tenant_review','payment waits seller review');
    oemCheck((int)$order['child_tenant_id']===0 && (int)$order['reserve_status']===0,'callback cannot provision or consume');
    oemCheck(Db::name('tenant_brand_payment')->where('order_id',$id)->count()===1,'same transaction idempotent');
    Payment::recordVerified(array_replace($receipt,['transaction_id'=>'trade-B']));
    oemCheck(Db::name('tenant_brand_payment')->where('order_id',$id)->count()===2,'distinct overpayment retained');
    oemCheck(Db::name('tenant_brand_refund')->where('order_id',$id)->count()===1,'overpayment refund queued once');
    Db::name('tenant_brand_quota_bucket')->insert(['tenant_id'=>$f['seller'],'package_id'=>$f['package'],'total_quota'=>1,'remaining_quota'=>1]);
    $reserved=Brand::createBrandOrder($f['seller'],$f['user'],5,$f['params']);
    $r=Flow::owned($f['seller'],$f['user'],$reserved['order_id']);
    oemCheck((int)$r['reserve_status']===1,'available quota reserved');
    Flow::cancel($f['seller'],$f['user'],$reserved['order_id']);
    oemCheck((int)Db::name('tenant_brand_quota_bucket')->where('tenant_id',$f['seller'])->value('reserved_quota')===0,'cancel releases once');
    Flow::cancel($f['seller'],$f['user'],$reserved['order_id']);
    Db::name('tenant_brand_payment_intent')->insert(['order_from'=>Brand::FROM_ORDER,'order_id'=>$reserved['order_id'],'tenant_id'=>$f['seller'],'pay_sn'=>$paySn.'late','channel'=>2,'merchant_id'=>'merchant-A','amount_cents'=>3101,'context'=>$context,'expires_at'=>time()-1]);
    Payment::recordVerified(array_replace($receipt,['pay_sn'=>$paySn.'late','transaction_id'=>'late-trade']));
    $late=Flow::owned($f['seller'],$f['user'],$reserved['order_id']);
    oemCheck($late['workflow_state']==='refunding' && (int)$late['closed_time']>0,'late payment refunds without reopening');
    oemCheck(Db::name('tenant_brand_refund')->where('order_id',$reserved['order_id'])->count()===1,'late refund recorded');
    $q=Brand::createQuotaOrder($f['seller'],9,5,$f['package'],2);
    oemCheck($q['order_amount']==='39.98','server procurement amount uses cents');
    Db::name('tenant')->where('id',$f['seller'])->update(['oem_flow_enabled'=>2]);
    oemReject(fn()=>Brand::createBrandOrder($f['seller'],$f['user'],5,$f['params']),'paused V2 entry silently fell back to legacy automatic opening');
    oemCheck(Flow::owned($f['seller'],$f['user'],$id)['workflow_state']==='tenant_review','paused entry retains existing paid orders');
    echo "PASS real OEM zero/available inventory, scope, payment validation, duplicates and late refund\n";
} finally { Db::rollback(); }
