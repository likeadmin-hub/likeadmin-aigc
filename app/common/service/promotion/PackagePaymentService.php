<?php
declare(strict_types=1);
namespace app\common\service\promotion;
use think\facade\Db;
use app\common\service\virtualpay\VirtualPayService as Orders;
use app\common\service\virtualpay\VirtualPayPolicy as Guard;
use app\common\enum\AdminTerminalEnum;
use app\common\logic\PayNotifyLogic;
/** Ordinary channels keep their SDKs; new package orders add durable pricing and receipt boundaries. */
final class PackagePaymentService
{
    private PackagePaymentGateway $gateway;
    public function __construct(?PackagePaymentGateway $gateway=null){$this->gateway=$gateway??app()->make(PackagePaymentGateway::class);}
    private function scoped(int $tenant,callable $fn): mixed
    {
        $source=request()->source;$previous=request()->tenantId;try{request()->source=AdminTerminalEnum::USER;request()->tenantId=$tenant;return $fn();}finally{request()->source=$source;request()->tenantId=$previous;}
    }
    public function prepay(string $from,array $order,int $channel,int $terminal,string $redirect): array
    {
        PackagePurchaseAvailability::assertOrder($from,$order);
        return $this->scoped((int)$order['tenant_id'],function()use($from,$order,$channel,$terminal,$redirect){
            $context=$this->gateway->call('context',$channel,$terminal,(int)$order['user_id']);
            $prepared=Db::transaction(function()use($from,$order,$channel,$terminal,$redirect,$context){
                $o=Orders::order((int)$order['tenant_id'],(int)$order['user_id'],$from,(int)$order['id'],true);
                PackagePurchaseAvailability::assertOrder($from,$o->toArray(),true);
                $s=Db::name('tenant_package_price_snapshot')->where(['tenant_id'=>$order['tenant_id'],'biz_type'=>$from,'order_id'=>$order['id']])->lock(true)->find();
                if(!$s||$s['status']!=='pending'||(int)$s['expires_at']<=time()||(int)$o['pay_status'])throw new \RuntimeException('订单已过期或已付款，请先查询原订单');
                $old=json_decode($s['payment_context']??'',true)?:[];
                if(trim((string)$o['pay_sn'])!==''){
                    if(!$old||(int)$o['pay_way']!==$channel||(int)$old['terminal']!==$terminal||$old['identity']!==$context)throw new \RuntimeException('原渠道支付单尚未关闭，请先取消并核对原订单后切换');
                }else{
                    $sn=(string)($o['sn']??$o['order_sn']);$paySn=$channel===2?'P'.bin2hex(random_bytes(15)):$sn;
                    $o->save(['pay_way'=>$channel,'pay_sn'=>$paySn]);Db::name('tenant_package_price_snapshot')->where('id',$s['id'])->update(['channel'=>$channel,'payment_context'=>json_encode(['terminal'=>$terminal,'identity'=>$context],JSON_THROW_ON_ERROR)]);
                }
                return $o->toArray()+['redirect_url'=>$redirect];
            });
            $prepared['sn']=$prepared['sn']??$prepared['order_sn'];$prepared['redirect_url']=$redirect;
            return $this->gateway->call('prepay',$channel,$terminal,(int)$order['user_id'],['from'=>$from,'order'=>$prepared]);
        });
    }
    private function inspect(int $tenant,int $user,string $from,int $id): array
    {
        $o=Orders::order($tenant,$user,$from,$id)->toArray();$s=PackagePricingService::snapshot($tenant,$from,$id);$context=json_decode($s['payment_context']??'',true)?:[];
        if(!$context||!in_array((int)$o['pay_way'],[2,3],true)||trim((string)$o['pay_sn'])==='')throw new \RuntimeException('原支付身份尚未确认，请联系管理员核对');
        $current=$this->gateway->call('context',(int)$o['pay_way'],(int)$context['terminal'],$user);
        if($current!==$context['identity'])throw new \RuntimeException('原收款配置已变化，请联系管理员核对原交易');
        $r=$this->gateway->call('query',(int)$o['pay_way'],(int)$context['terminal'],$user,['pay_sn'=>$o['pay_sn']]);Guard::assertRuntime();
        if($r['order_sn']!==$o['pay_sn']||$r['app_id']!==$current['app_id']||$r['merchant']!==$current['merchant']||$r['currency']!=='CNY'||(int)$r['amount_cents']!==(int)$s['payable_cents'])throw new \RuntimeException('原渠道订单、金额或收款身份不匹配，请人工核对');
        return [$o,$s,$context,$r];
    }
    public function reconcile(int $tenant,int $user,string $from,int $id): array
    {
        return $this->scoped($tenant,function()use($tenant,$user,$from,$id){[$o,$s,$c,$r]=$this->inspect($tenant,$user,$from,$id);if($r['state']==='SUCCESS')$this->confirmed($o,$s,$r);return $r;});
    }
    public function cancel(int $tenant,int $user,string $from,int $id): void
    {
        $this->scoped($tenant,function()use($tenant,$user,$from,$id){
            [$o,$s,$c,$r]=$this->inspect($tenant,$user,$from,$id);
            if($r['state']==='SUCCESS'){$this->confirmed($o,$s,$r);throw new \RuntimeException('原订单已付款，请查看到账结果');}
            if($r['state']==='NOTPAY'){Guard::assertRuntime();$this->gateway->call('close',(int)$o['pay_way'],(int)$c['terminal'],$user,['pay_sn'=>$o['pay_sn']]);[$o,$s,$c,$r]=$this->inspect($tenant,$user,$from,$id);}
            if($r['state']==='SUCCESS'){$this->confirmed($o,$s,$r);throw new \RuntimeException('关单期间订单已付款，请查看到账结果');}
            if(!in_array($r['state'],['CLOSED','REVOKED'],true))throw new \RuntimeException('原支付结果仍在核对，优惠资格会继续保留');
            PackagePricingService::closeConfirmed($tenant,$user,$from,$id,'closed');
        });
    }
    private function confirmed(array $o,array $s,array $r): void
    {
        if(trim((string)$r['transaction'])==='')throw new \RuntimeException('渠道未返回交易编号，请稍后核对');
        $receipt=Db::transaction(function()use($o,$s,$r){
            $order=Orders::order((int)$s['tenant_id'],(int)$s['user_id'],$s['biz_type'],(int)$s['order_id'],true);$snapshot=Db::name('tenant_package_price_snapshot')->where('id',$s['id'])->lock(true)->find();
            $old=Db::name('package_payment_receipt')->where(['channel'=>$o['pay_way'],'transaction_id'=>$r['transaction']])->lock(true)->find();
            if($old){if((int)$old['snapshot_id']!==(int)$s['id'])throw new \RuntimeException('交易编号已关联其他订单');return $old;}
            $previous=Db::name('package_payment_receipt')->where('snapshot_id',$s['id'])->lock(true)->find();$manual=$previous||$snapshot['status']==='closed'||(int)$order['pay_status'];
            if(!$manual)PackagePricingService::confirmPaymentCoupon((int)$s['tenant_id'],$s['biz_type'],(int)$s['order_id']);
            $id=Db::name('package_payment_receipt')->insertGetId(['tenant_id'=>$s['tenant_id'],'snapshot_id'=>$s['id'],'channel'=>$o['pay_way'],'transaction_id'=>$r['transaction'],'amount_cents'=>$s['payable_cents'],'status'=>$manual?'manual':'paid','message'=>$manual?'重复或关闭后的到账，请按原交易核对退款':'','created_at'=>time(),'updated_at'=>time()]);return Db::name('package_payment_receipt')->where('id',$id)->find();
        });
        if($receipt['status']!=='paid')return;
        Db::transaction(function()use($s,$r,$receipt){
            $order=Orders::order((int)$s['tenant_id'],(int)$s['user_id'],$s['biz_type'],(int)$s['order_id'],true);Guard::assertRuntime();
            if(!(int)$order['pay_status']){$result=PayNotifyLogic::handle($s['biz_type'],$s['order_sn'],['transaction_id'=>$r['transaction']]);if($result!==true)throw new \RuntimeException('到账已记录，权益待重新核对');}
            Db::name('package_payment_receipt')->where('id',$receipt['id'])->update(['status'=>'granted','updated_at'=>time()]);
        });
    }
    /** Called only after SDK signature verification. New records are re-queried against their frozen identity. */
    public function callback(int $channel,string $paySn): bool
    {
        $s=Db::name('tenant_package_price_snapshot')->alias('s')->join('recharge_order r','s.biz_type="recharge" AND s.order_id=r.id AND s.tenant_id=r.tenant_id')->where('r.pay_sn',$paySn)->field('s.*')->find();
        if(!$s)$s=Db::name('tenant_package_price_snapshot')->alias('s')->join('membership_order m','s.biz_type="membership" AND s.order_id=m.id AND s.tenant_id=m.tenant_id')->where('m.pay_sn',$paySn)->field('s.*')->find();
        if(!$s)return false;if((int)$s['channel']!==$channel)throw new \RuntimeException('付款通知渠道不匹配');
        $this->reconcile((int)$s['tenant_id'],(int)$s['user_id'],$s['biz_type'],(int)$s['order_id']);return true;
    }
}
