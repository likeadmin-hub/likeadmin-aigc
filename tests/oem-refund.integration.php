<?php
declare(strict_types=1);
require __DIR__.'/Support/OemDisposable.php';
require __DIR__.'/Support/OemFixtures.php';
use think\facade\Db;
use app\common\service\brand\TenantBrandService as Brand;
use app\common\service\brand\BrandWorkflowService as Flow;
use app\common\service\brand\BrandRefundService as Refund;
use app\common\service\brand\BrandChannelGateway;
use app\common\service\brand\BrandPlatformService as Platform;
use app\common\service\brand\BrandProvisionService as Provision;
oemCheck(class_exists(Refund::class),'Original-channel refund executor is missing');
class RefundGatewayFixture implements \app\common\service\brand\BrandRefundGateway {
    public array $submitted=[]; public array $queries=[]; public array $results=[]; public bool $loseResponse=false;
    public function refund(array $payment,array $refund): array {
        $this->submitted[]=$refund['refund_sn'];
        if($this->loseResponse) { $this->loseResponse=false; throw new RuntimeException('gateway timeout after request'); }
        return ['status'=>'pending'];
    }
    public function queryRefund(array $payment,array $refund): ?array { $this->queries[]=$refund['refund_sn']; return $this->results[$refund['refund_sn']]??null; }
}
function refundResult(array $refund,array $payment,string $status='success'): array {
    return ['status'=>$status,'refund_sn'=>$refund['refund_sn'],'transaction_id'=>$payment['transaction_id'],'merchant_id'=>$payment['merchant_id'],
        'amount_cents'=>(int)$refund['amount_cents'],'currency'=>'CNY','channel_refund_id'=>'channel-'.$refund['refund_sn']];
}
Db::startTrans();
try {
    $gateway=new RefundGatewayFixture(); app()->instance(BrandChannelGateway::class,$gateway);
    $f=oemFixture(); Db::name('tenant_brand_quota_bucket')->insert(['tenant_id'=>$f['seller'],'package_id'=>$f['package'],'total_quota'=>3,'remaining_quota'=>3]);
    $id=Brand::createBrandOrder($f['seller'],$f['user'],4,$f['params'])['order_id']; oemPaid($f,$id);
    Refund::request($f['seller'],$f['user'],$id,'不再需要');
    oemReject(fn()=>Refund::review('tenant',$f['seller']+1,9,$id,'approve','核对完成'),'other seller approved refund');
    Refund::review('tenant',$f['seller'],9,$id,'deny','请确认品牌资料');
    oemCheck(Flow::owned($f['seller'],$f['user'],$id)['workflow_state']==='tenant_review','denied refund restores prior review');
    Flow::review($f['seller'],9,$id,'reject','无法交付');
    $refund=Db::name('tenant_brand_refund')->where('order_id',$id)->find(); $payment=Db::name('tenant_brand_payment')->where('id',$refund['payment_id'])->find();
    Refund::process((int)$refund['id']);
    oemCheck(Db::name('tenant_brand_refund')->where('id',$refund['id'])->value('status')==='processing','accepted request is not successful');
    oemCheck((int)Flow::owned($f['seller'],$f['user'],$id)['reserve_status']===1,'unconfirmed refund preserves frozen quota');
    $gateway->results[$refund['refund_sn']]=refundResult($refund,$payment);
    Db::name('tenant_brand_refund')->where('id',$refund['id'])->update(['next_check_time'=>0]); Refund::process((int)$refund['id']);
    oemCheck(Flow::owned($f['seller'],$f['user'],$id)['workflow_state']==='refunded','confirmed refund completes workflow');
    oemCheck((int)Flow::owned($f['seller'],$f['user'],$id)['reserve_status']===3,'confirmed refund releases freeze');
    Refund::process((int)$refund['id']);
    $order=Flow::owned($f['seller'],$f['user'],$id);
    oemCheck(count($gateway->submitted)===1 && Db::name('tenant_brand_quota_log')->where(['source_sn'=>$order['order_sn'],'change_type'=>'rollback'])->count()===1,'repeated completion cannot submit or release again');
    $id2=Brand::createBrandOrder($f['seller'],$f['user'],4,$f['params'])['order_id']; oemPaid($f,$id2); Flow::review($f['seller'],9,$id2,'reject','取消交付');
    $r2=Db::name('tenant_brand_refund')->where('order_id',$id2)->find(); $p2=Db::name('tenant_brand_payment')->where('id',$r2['payment_id'])->find();
    $gateway->loseResponse=true; Refund::process((int)$r2['id']);
    oemCheck(Db::name('tenant_brand_refund')->where('id',$r2['id'])->value('status')==='unknown','timeout preserves unknown outcome');
    $gateway->results[$r2['refund_sn']]=refundResult($r2,$p2);
    Db::name('tenant_brand_refund')->where('id',$r2['id'])->update(['next_check_time'=>0]); Refund::process((int)$r2['id']);
    oemCheck(count(array_filter($gateway->submitted,fn($sn)=>$sn===$r2['refund_sn']))===1,'unknown result queries original request instead of charging twice');
    $id3=Brand::createBrandOrder($f['seller'],$f['user'],4,$f['params'])['order_id']; oemPaid($f,$id3); Flow::review($f['seller'],9,$id3,'approve',''); Flow::submit($f['seller'],9,$id3); Platform::confirm($id3,1);
    $job=Provision::claim('refund-worker'); Provision::advance($job);
    Refund::request($f['seller'],$f['user'],$id3,'申请退款');
    oemReject(fn()=>Refund::review('tenant',$f['seller'],9,$id3,'approve','核对完成'),'seller refunded executing order');
    oemReject(fn()=>Refund::review('platform',0,1,$id3,'approve','核对完成'),'refund before worker acknowledges stop');
    oemCheck(!Provision::advance($job),'refund request stops worker at fence');
    Refund::review('platform',0,1,$id3,'approve','已核对未激活租户，无已交付权益');
    oemCheck(!Provision::advance($job),'old lease cannot deliver after refund approval');
    oemReject(fn()=>Platform::retry($id3,1),'refunding order cannot resume opening');
    oemCheck((int)Db::name('tenant_brand_quota_bucket')->where('tenant_id',$f['seller'])->value('used_quota')===0,'failed partial provision never consumes quota');
    $r3=Db::name('tenant_brand_refund')->where('order_id',$id3)->find(); $p3=Db::name('tenant_brand_payment')->where('id',$r3['payment_id'])->find();
    Refund::process((int)$r3['id']);
    $gateway->results[$r3['refund_sn']]=array_replace(refundResult($r3,$p3),['amount_cents'=>1]);
    Db::name('tenant_brand_refund')->where('id',$r3['id'])->update(['next_check_time'=>0]); Refund::process((int)$r3['id']);
    oemCheck(Flow::owned($f['seller'],$f['user'],$id3)['workflow_state']==='refund_failed' && (int)Flow::owned($f['seller'],$f['user'],$id3)['reserve_status']===1,'wrong refund amount preserves freeze for human reconciliation');
    Refund::retry($id3,1,'已恢复原收款配置'); $gateway->results[$r3['refund_sn']]=refundResult($r3,$p3);
    Refund::process((int)$r3['id']);
    oemCheck(Flow::owned($f['seller'],$f['user'],$id3)['workflow_state']==='refunded','same refund number confirms after reconciliation');
    $id4=Brand::createBrandOrder($f['seller'],$f['user'],4,$f['params'])['order_id']; oemPaid($f,$id4);
    $primary=Db::name('tenant_brand_payment')->where('order_id',$id4)->find(); $intent=Db::name('tenant_brand_payment_intent')->where('id',$primary['intent_id'])->find();
    \app\common\service\brand\BrandPaymentService::recordVerified(['pay_sn'=>$intent['pay_sn'],'channel'=>2,'merchant_id'=>$intent['merchant_id'],'app_id'=>'fixture-app','transaction_id'=>'extra-'.$id4,'amount_cents'=>3101,'currency'=>'CNY','status'=>'success']);
    $duplicate=Db::name('tenant_brand_payment')->where(['order_id'=>$id4,'disposition'=>'duplicate'])->find();
    $r4=Db::name('tenant_brand_refund')->where('payment_id',$duplicate['id'])->find(); Refund::process((int)$r4['id']);
    $gateway->results[$r4['refund_sn']]=refundResult($r4,$duplicate);
    Db::name('tenant_brand_refund')->where('id',$r4['id'])->update(['next_check_time'=>0]); Refund::process((int)$r4['id']);
    oemCheck(Flow::owned($f['seller'],$f['user'],$id4)['workflow_state']==='tenant_review' && (int)Flow::owned($f['seller'],$f['user'],$id4)['reserve_status']===1,'duplicate refund never cancels primary application');
    $q=Flow::createQuota($f['seller'],9,4,$f['package'],1);oemPaid($f,$q['order_id'],Brand::FROM_QUOTA);
    $qp=Db::name('tenant_brand_payment')->where(['order_id'=>$q['order_id'],'order_from'=>Brand::FROM_QUOTA])->find();$qi=Db::name('tenant_brand_payment_intent')->where('id',$qp['intent_id'])->find();
    \app\common\service\brand\BrandPaymentService::recordVerified(['pay_sn'=>$qi['pay_sn'],'channel'=>2,'merchant_id'=>$qi['merchant_id'],'app_id'=>'fixture-app','transaction_id'=>'extra-quota-'.$q['order_id'],'amount_cents'=>1999,'currency'=>'CNY','status'=>'success']);
    $qr=Db::name('tenant_brand_refund')->where(['order_id'=>$q['order_id'],'order_from'=>Brand::FROM_QUOTA])->find();$payment=Db::name('tenant_brand_payment')->where('id',$qr['payment_id'])->find();
    Refund::process((int)$qr['id']);$gateway->results[$qr['refund_sn']]=refundResult($qr,$payment,'failed');Db::name('tenant_brand_refund')->where('id',$qr['id'])->update(['next_check_time'=>0]);Refund::process((int)$qr['id']);
    oemCheck(Db::name('tenant_brand_refund')->where('id',$qr['id'])->value('status')==='failed','quota failed refund persists');
    $bucketBefore=Db::name('tenant_brand_quota_bucket')->where('tenant_id',$f['seller'])->find();$submissions=count($gateway->submitted);
    Refund::retryTransaction((int)$qr['id'],1,'平台已核对原商户');$gateway->results[$qr['refund_sn']]=refundResult($qr,$payment);Refund::process((int)$qr['id']);
    oemCheck(count($gateway->submitted)===$submissions && end($gateway->queries)===$qr['refund_sn'],'procurement retry queries the fixed original refund before submission');
    oemCheck(Db::name('tenant_brand_refund')->where('id',$qr['id'])->value('status')==='success','procurement excess refund can recover');
    oemCheck(Db::name('tenant_brand_quota_bucket')->where('tenant_id',$f['seller'])->find()===$bucketBefore,'procurement refund never adds or reverses normal quota');
    $late=Flow::createQuota($f['seller'],9,4,$f['package'],1);Db::name('tenant_brand_quota_order')->where('id',$late['order_id'])->update(['closed_time'=>time()]);
    oemPaid($f,$late['order_id'],Brand::FROM_QUOTA);$lr=Db::name('tenant_brand_refund')->where(['order_id'=>$late['order_id'],'order_from'=>Brand::FROM_QUOTA])->find();$lp=Db::name('tenant_brand_payment')->where('id',$lr['payment_id'])->find();
    Refund::process((int)$lr['id']);$gateway->results[$lr['refund_sn']]=refundResult($lr,$lp,'failed');Db::name('tenant_brand_refund')->where('id',$lr['id'])->update(['next_check_time'=>0]);Refund::process((int)$lr['id']);
    Refund::retryTransaction((int)$lr['id'],1,'迟到付款，核对原交易');$gateway->results[$lr['refund_sn']]=refundResult($lr,$lp);Refund::process((int)$lr['id']);
    oemCheck(Db::name('tenant_brand_refund')->where('id',$lr['id'])->value('status')==='success','late procurement refund recovers');oemCheck(Db::name('tenant_brand_quota_bucket')->where('tenant_id',$f['seller'])->find()===$bucketBefore,'closed procurement never adds quota');
    oemCheck(Brand::formatQuotaOrder(Db::name('tenant_brand_quota_order')->where('id',$late['order_id'])->find())['pay_status_desc']==='已原路退款','late procurement cannot display quota received');
    echo "PASS real refund review, channel confirmation, unknown-result query and worker fencing\n";
} finally { Db::rollback(); }
