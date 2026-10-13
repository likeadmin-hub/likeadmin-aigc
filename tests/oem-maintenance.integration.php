<?php
declare(strict_types=1);
require __DIR__.'/Support/OemDisposable.php'; require __DIR__.'/Support/OemFixtures.php'; require __DIR__.'/Support/OemFakeGateway.php';
use think\facade\Db;
use app\common\service\brand\BrandMaintenanceService as Maintenance;
use app\common\service\brand\TenantBrandService as Brand;
use app\common\service\brand\BrandWorkflowService as Flow;
oemCheck(class_exists(Maintenance::class),'Overdue reminders and scoped operating statistics are missing');
Db::startTrans(); try {
    $f=oemFixture(); Db::name('tenant_brand_quota_bucket')->insert(['tenant_id'=>$f['seller'],'package_id'=>$f['package'],'total_quota'=>2,'remaining_quota'=>2]);
    $id=Brand::createBrandOrder($f['seller'],$f['user'],4,$f['params'])['order_id']; oemPaid($f,$id);
    Db::name('tenant_brand_order')->where('id',$id)->update(['pay_time'=>time()-86401]);
    oemCheck(Maintenance::remind()===1,'paid overdue order creates a reminder');
    oemCheck(Maintenance::remind()===0,'same-day reminder is idempotent');
    oemCheck(Db::name('tenant_brand_event')->where(['order_id'=>$id,'action'=>'overdue_reminder'])->count()===1,'one durable reminder');
    oemCheck(Flow::owned($f['seller'],$f['user'],$id)['workflow_state']==='tenant_review','reminder never refunds or changes approval');
    $quota=Flow::createQuota($f['seller'],9,4,$f['package'],2); oemPaid($f,$quota['order_id'],Brand::FROM_QUOTA);
    $s=Maintenance::statistics($f['seller']);
    oemCheck($s['order_count']===1 && $s['customer_received']==='31.01' && $s['procurement_amount']==='39.98' && $s['refund_amount']==='0.00','scoped money ledger statistics');
    oemCheck($s['available_quota']===3 && $s['frozen_quota']===1 && $s['used_quota']===0 && $s['overdue_count']===1,'quota and overdue statistics');
    $other=oemFixture(); oemCheck(Maintenance::statistics($other['seller'])['customer_received']==='0.00','seller statistics cannot disclose others');
    Db::name('tenant_brand_order')->where('id',$id)->update(['workflow_state'=>'delivered']);
    Db::name('tenant_brand_order')->where('id',$id)->update(['last_remind_time'=>0]);
    oemCheck(Maintenance::remind()===0,'delivered order does not trigger reminders');
    $old=Brand::createBrandOrder($f['seller'],$f['user'],4,$f['params'])['order_id'];$gate=new OemFakeGateway();app()->instance(\app\common\service\brand\BrandChannelGateway::class,$gate);
    \app\common\service\brand\BrandPaymentService::prepay(Brand::FROM_ORDER,Flow::owned($f['seller'],$f['user'],$old),2,'/');Flow::cancel($f['seller'],$f['user'],$old);
    $intent=Db::name('tenant_brand_payment_intent')->where(['order_id'=>$old,'order_from'=>Brand::FROM_ORDER])->find();Db::name('tenant_brand_payment_intent')->where('id','<>',$intent['id'])->update(['last_query_time'=>time()]);Db::name('tenant_brand_payment_intent')->where('id',$intent['id'])->update(['create_time'=>time()-8*86400,'last_query_time'=>0]);
    $gate->receipts[$intent['pay_sn']]=['pay_sn'=>$intent['pay_sn'],'channel'=>2,'merchant_id'=>'seller-A','app_id'=>'app-A','transaction_id'=>'old-maintenance-'.$old,'amount_cents'=>3101,'currency'=>'CNY','status'=>'success'];Maintenance::run();
    oemCheck(Flow::owned($f['seller'],$f['user'],$old)['workflow_state']==='refunding','Older unconfirmed intent must be queried and late money refunded, without a seven-day cutoff');
    echo "PASS once-daily reminders, unchanged business workflow and scoped ledger statistics\n";
} finally { Db::rollback(); }
