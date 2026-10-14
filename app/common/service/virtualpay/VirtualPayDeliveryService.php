<?php
declare(strict_types=1);
namespace app\common\service\virtualpay;
use think\facade\Db;
use app\common\enum\AdminTerminalEnum;
use app\common\model\user\User;
use app\common\model\membership\UserMembership;
use app\common\logic\PayNotifyLogic;
final class VirtualPayDeliveryService
{
    private VirtualPayService $pay;
    public function __construct(?VirtualPayService $pay=null){$this->pay=$pay??new VirtualPayService();}
    public function grant(int $id): void
    {
        $preview=Db::name('virtual_pay_intent')->where('id',$id)->find();if(!$preview)throw new \RuntimeException('支付记录不存在');
        $source=request()->source;$tenant=request()->tenantId;
        try{
            request()->source=AdminTerminalEnum::USER;request()->tenantId=(int)$preview['tenant_id'];
            Db::transaction(function()use($preview,$id){
                $order=VirtualPayService::order((int)$preview['tenant_id'],(int)$preview['user_id'],$preview['biz_type'],(int)$preview['order_id'],true);
                $snapshot=Db::name('tenant_package_price_snapshot')->where(['tenant_id'=>$preview['tenant_id'],'biz_type'=>$preview['biz_type'],'order_id'=>$preview['order_id']])->lock(true)->find();
                $i=Db::name('virtual_pay_intent')->where('id',$id)->lock(true)->find();
                if($i['delivery_status']==='granted')return;
                if($i['status']!=='paid'||$i['refund_status']!=='none'||$i['delivery_status']==='blocked')throw new \RuntimeException('该款项需先核对退款或重复付款，暂不发放权益');
                if(!$snapshot||$snapshot['status']!=='pending')throw new \RuntimeException('订单状态需人工核对，暂不发放权益');
                if((int)$order['pay_status'])throw new \RuntimeException('订单已通过其他交易付款，需核对重复款项');
                $user=User::where(['tenant_id'=>$i['tenant_id'],'id'=>$i['user_id']])->lock(true)->findOrEmpty();if($user->isEmpty())throw new \RuntimeException('付款用户不存在，已到账款项需人工核对');
                $before=['user_money'=>$user->user_money,'total_recharge_amount'=>$user->total_recharge_amount];
                if($i['biz_type']==='membership')$before['membership']=UserMembership::where(['tenant_id'=>$i['tenant_id'],'user_id'=>$i['user_id']])->findOrEmpty()->toArray();
                $result=PayNotifyLogic::handle($i['biz_type'],(string)$snapshot['order_sn'],['transaction_id'=>$i['wx_order_id']]);
                if($result!==true)throw new \RuntimeException('付款已确认，权益发放失败：'.(string)$result);
                Db::name('virtual_pay_intent')->where('id',$id)->update(['delivery_status'=>'granted','rights_before'=>json_encode($before,JSON_THROW_ON_ERROR),'last_error'=>'','updated_at'=>time()]);
                foreach(['xpay_shipping','general_shipping'] as $kind)Db::name('virtual_pay_work')->extra('IGNORE')->insert(['intent_id'=>$id,'kind'=>$kind,'next_at'=>time(),'updated_at'=>time()]);
                Db::name('virtual_pay_work')->where(['intent_id'=>$id,'kind'=>'delivery'])->update(['status'=>'done','updated_at'=>time()]);
            });
        }catch(\Throwable $e){Db::name('virtual_pay_intent')->where('id',$id)->update(['last_error'=>mb_substr($e->getMessage(),0,500),'updated_at'=>time()]);throw $e;}
        finally{request()->source=$source;request()->tenantId=$tenant;}
    }
    public function syncXpay(int $id): bool
    {
        $i=Db::name('virtual_pay_intent')->where('id',$id)->find();if(!$i||$i['delivery_status']!=='granted'||$i['refund_status']!=='none')throw new \RuntimeException('权益交付尚未完成或正在退款，不能确认发货');
        $o=$this->pay->query($i);
        if((int)$o['status']===4){Db::name('virtual_pay_intent')->where('id',$id)->update(['xpay_shipping_status'=>'confirmed','updated_at'=>time()]);return true;}
        if(!in_array((int)$o['status'],[2,3],true))throw new \RuntimeException('渠道发货状态需要核对');
        $v=VirtualPayConfigService::version((int)$i['tenant_id'],(int)$i['config_version_id']);
        $this->pay->gateway()->request($v,'/xpay/notify_provide_goods',['order_id'=>$i['out_trade_no'],'env'=>(int)$i['env']],false);
        Db::name('virtual_pay_intent')->where('id',$id)->update(['xpay_shipping_status'=>'confirming','updated_at'=>time()]);return false;
    }
}
