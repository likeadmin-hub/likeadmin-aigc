<?php
declare(strict_types=1);
namespace app\common\service\virtualpay;
use think\facade\Db;
use app\common\service\promotion\PackagePricingService as Price;
use app\common\model\recharge\RechargeOrder;
use app\common\model\membership\MembershipOrder;
final class VirtualPayService
{
    public const CHANNEL=4;
    private VirtualPayGateway $gateway;
    public function __construct(?VirtualPayGateway $gateway=null){$this->gateway=$gateway??app()->make(VirtualPayGateway::class);}
    public function gateway(): VirtualPayGateway {return $this->gateway;}
    public static function order(int $tenant,int $user,string $from,int $id,bool $lock=false)
    {
        if(!in_array($from,['recharge','membership'],true)||$tenant<=0||$user<=0)throw new \RuntimeException('支付订单类型或身份不正确');
        $query=$from==='recharge'?RechargeOrder::withoutGlobalScope()->where(['tenant_id'=>$tenant,'user_id'=>$user,'id'=>$id]):MembershipOrder::withoutGlobalScope()->where(['tenant_id'=>$tenant,'user_id'=>$user,'id'=>$id]);
        if($lock)$query->lock(true);$o=$query->findOrEmpty();if($o->isEmpty())throw new \RuntimeException('待支付订单不存在');return $o;
    }
    public static function intent(int $tenant,string $app,string $sn): array
    {
        $i=Db::name('virtual_pay_intent')->where(['tenant_id'=>$tenant,'app_id'=>$app,'out_trade_no'=>$sn])->find();if(!$i)throw new \RuntimeException('支付记录不存在，请联系管理员核对');return $i;
    }
    public function prepay(int $tenant,int $user,int $terminal,string $from,int $orderId,string $session,string $platform,int $env=0): array
    {
        if($terminal!==1)throw new \RuntimeException('虚拟支付仅支持微信小程序');
        $v=VirtualPayConfigService::active($tenant,$env);$s=(new VirtualPaySessionService($this->gateway))->get($tenant,$user,$v['app_id'],$session);
        return Db::transaction(function()use($tenant,$user,$from,$orderId,$platform,$env,$v,$s){
            $o=self::order($tenant,$user,$from,$orderId,true);if((int)$o['pay_status'])throw new \RuntimeException('订单已支付，请查看交付结果');
            $snapshot=Db::name('tenant_package_price_snapshot')->where(['tenant_id'=>$tenant,'biz_type'=>$from,'order_id'=>$orderId])->lock(true)->find();
            if(!$snapshot)throw new \RuntimeException('旧订单请使用原支付方式或重新选择套餐');
            if($snapshot['status']!=='pending'||(int)$snapshot['expires_at']<=time())throw new \RuntimeException('订单已过期，请先核对原支付结果再重新购买');
            VirtualPayPolicy::client($platform,$env,(int)$snapshot['payable_cents']);
            if(trim((string)$o['pay_sn'])!==''&&(int)$o['pay_way']!==self::CHANNEL)throw new \RuntimeException('原支付单尚未关闭，请先核对原渠道状态');
            $existing=Db::name('virtual_pay_intent')->where(['tenant_id'=>$tenant,'biz_type'=>$from,'order_id'=>$orderId])->where('status','<>','closed')->order('id desc')->lock(true)->find();
            if($existing){
                if($existing['app_id']!==$v['app_id']||(int)$existing['env']!==$env)throw new \RuntimeException('订单已在原小程序或支付环境发起，请先核对原支付结果');
                $original=VirtualPayConfigService::version($tenant,(int)$existing['config_version_id']);$sign=VirtualPayPolicy::sign(json_decode($existing['sign_data'],true,512,JSON_THROW_ON_ERROR),VirtualPayConfigService::secret($original,'app_key'),$s['session_key']);
                return $sign+['out_trade_no'=>$existing['out_trade_no'],'order_amount'=>\app\common\service\promotion\PackagePromotionPolicy::amount((int)$existing['amount_cents']),'expires_at'=>(int)$existing['expires_at']];
            }
            $variant=(int)$snapshot['is_discount']?'discount':'regular';$product=VirtualPayConfigService::product($tenant,$v['app_id'],$env,$from,(int)$snapshot['package_id'],$snapshot['cycle'],$variant);
            if((int)$product['price_cents']!==(int)$snapshot['payable_cents'])throw new \RuntimeException('当前道具价格与订单不一致，请联系管理员核对');
            $sn='V'.bin2hex(random_bytes(15));$attach='vp:'.$sn;
            $data=['offerId'=>$v['offer_id'],'buyQuantity'=>1,'env'=>$env,'currencyType'=>'CNY','productId'=>$product['product_id'],'goodsPrice'=>(int)$snapshot['payable_cents'],'outTradeNo'=>$sn,'attach'=>$attach];
            $sign=VirtualPayPolicy::sign($data,VirtualPayConfigService::secret($v,'app_key'),$s['session_key']);
            Db::name('virtual_pay_intent')->insert(['tenant_id'=>$tenant,'user_id'=>$user,'biz_type'=>$from,'order_id'=>$orderId,'config_version_id'=>$v['id'],'app_id'=>$v['app_id'],'env'=>$env,'openid'=>$s['openid'],'out_trade_no'=>$sn,'platform'=>$platform,'product_id'=>$product['product_id'],'amount_cents'=>$snapshot['payable_cents'],'attach'=>$attach,'sign_data'=>$sign['signData'],'created_at'=>time(),'updated_at'=>time(),'expires_at'=>$snapshot['expires_at']]);
            $o->save(['pay_way'=>self::CHANNEL,'pay_sn'=>$sn]);Db::name('tenant_package_price_snapshot')->where('id',$snapshot['id'])->update(['channel'=>self::CHANNEL]);
            return $sign+['out_trade_no'=>$sn,'order_amount'=>\app\common\service\promotion\PackagePromotionPolicy::amount((int)$snapshot['payable_cents']),'expires_at'=>(int)$snapshot['expires_at']];
        });
    }
    public function query(array $intent): array
    {
        $v=VirtualPayConfigService::version((int)$intent['tenant_id'],(int)$intent['config_version_id']);
        $r=$this->gateway->request($v,'/xpay/query_order',['openid'=>$intent['openid'],'env'=>(int)$intent['env'],'order_id'=>$intent['out_trade_no']]);$o=$r['order']??null;
        if(!is_array($o)||($o['order_id']??'')!==$intent['out_trade_no']||(int)($o['env_type']??0)!==(int)$intent['env']+1)throw new \RuntimeException('渠道订单身份或环境核对失败');
        if((int)($o['order_fee']??-1)!==(int)$intent['amount_cents']||(in_array((int)($o['status']??0),[2,3,4,5,8],true)&&(int)($o['paid_fee']??-1)!==(int)$intent['amount_cents']))throw new \RuntimeException('渠道订单金额核对失败');
        if(isset($o['biz_meta'])&&$o['biz_meta']!==''&&$o['biz_meta']!==$intent['attach'])throw new \RuntimeException('渠道订单商品关联信息核对失败');return $o;
    }
    public function reconcile(array $intent, string $expectedTransaction = ''): array
    {
        $o=$this->query($intent);$state=(int)$o['status'];
        if ($expectedTransaction !== '' && ($o['wxpay_order_id']??'') !== $expectedTransaction) throw new \RuntimeException('微信交易编号不匹配');
        if(in_array($state,[2,3,4,5,8],true)){
            $this->confirm($intent,$o);
            if(in_array($state,[2,3,4],true))(new VirtualPayDeliveryService($this))->grant((int)$intent['id']);
            // Refund states are reconciled by the dedicated refund service; never turn debit state5 into success.
        }elseif($state===6){Db::name('virtual_pay_intent')->where(['id'=>$intent['id'],'status'=>'pending'])->update(['status'=>'closed','updated_at'=>time()]);}
        return $o;
    }
    private function confirm(array $intent,array $query): void
    {
        $tx=(string)($query['wx_order_id']??'');if($tx==='')throw new \RuntimeException('渠道未返回交易编号，暂不能确认到账');
        Db::transaction(function()use($intent,$query,$tx){
            $order=self::order((int)$intent['tenant_id'],(int)$intent['user_id'],$intent['biz_type'],(int)$intent['order_id'],true);
            $snapshot=Db::name('tenant_package_price_snapshot')->where(['tenant_id'=>$intent['tenant_id'],'biz_type'=>$intent['biz_type'],'order_id'=>$intent['order_id']])->lock(true)->find();
            $i=Db::name('virtual_pay_intent')->where('id',$intent['id'])->lock(true)->find();
            $old=Db::name('virtual_pay_receipt')->where(['app_id'=>$i['app_id'],'env'=>$i['env'],'transaction_key'=>$tx])->lock(true)->find();
            if($old){if((int)$old['intent_id']!==(int)$i['id'])throw new \RuntimeException('交易编号已关联其他订单，需要人工核对');return;}
            if($i['wx_order_id']!==''&&$i['wx_order_id']!==$tx)throw new \RuntimeException('同一支付尝试返回了不同交易，请人工核对');
            $kind=in_array((int)$query['status'],[5,8],true)?'refunded':'payment';if(!$snapshot||$snapshot['status']==='closed')$kind='late';elseif((int)$order['pay_status']&&$i['delivery_status']!=='granted')$kind='duplicate';
            Db::name('virtual_pay_receipt')->insert(['tenant_id'=>$i['tenant_id'],'intent_id'=>$i['id'],'app_id'=>$i['app_id'],'env'=>$i['env'],'transaction_key'=>$tx,'amount_cents'=>$i['amount_cents'],'kind'=>$kind,'normalized'=>json_encode($query,JSON_THROW_ON_ERROR),'created_at'=>time()]);
            Db::name('virtual_pay_intent')->where('id',$i['id'])->update(['status'=>'paid','wx_order_id'=>$tx,'wxpay_order_id'=>(string)($query['wxpay_order_id']??''),'order_type'=>(int)($query['order_type']??0),'paid_at'=>time(),'refund_status'=>$kind==='payment'?'none':($kind==='refunded'?'processing':'required'),'delivery_status'=>$kind==='payment'?'pending':'blocked','updated_at'=>time()]);
            Db::name('virtual_pay_work')->extra('IGNORE')->insert(['intent_id'=>$i['id'],'kind'=>$kind==='payment'?'delivery':'refund','next_at'=>time(),'updated_at'=>time()]);
        });
    }
    public function status(int $tenant,int $user,string $from,int $id,bool $query=true): array
    {
        $order=self::order($tenant,$user,$from,$id);$i=Db::name('virtual_pay_intent')->where(['tenant_id'=>$tenant,'user_id'=>$user,'biz_type'=>$from,'order_id'=>$id])->order('id desc')->find();
        if(!$i)throw new \RuntimeException('尚未发起虚拟支付');
        if($query&&$i['refund_status']==='none'){try{$this->reconcile($i);}catch(\Throwable $e){Db::name('virtual_pay_intent')->where('id',$i['id'])->update(['last_error'=>mb_substr($e->getMessage(),0,500),'updated_at'=>time()]);}}
        $i=Db::name('virtual_pay_intent')->where('id',$i['id'])->find();
        return ['order_id'=>$id,'from'=>$from,'order_amount'=>\app\common\service\promotion\PackagePromotionPolicy::amount((int)$i['amount_cents']),'payment_status'=>$i['status'],'delivery_status'=>$i['delivery_status'],'shipping_status'=>$i['xpay_shipping_status'],'general_shipping_status'=>$i['general_shipping_status'],'refund_status'=>$i['refund_status'],'expires_at'=>(int)$i['expires_at'],'message'=>$i['last_error']!==''?'支付或交付仍在核对，请稍后查询或联系管理员':'','confirm_receipt'=>false];
    }
}
