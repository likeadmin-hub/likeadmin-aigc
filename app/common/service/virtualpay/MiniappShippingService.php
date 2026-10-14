<?php
declare(strict_types=1);
namespace app\common\service\virtualpay;
use think\facade\Db;
/** XPay delivery and generic WeChat order shipping are independent protocols. */
final class MiniappShippingService
{
    private VirtualPayService $pay;
    public function __construct(?VirtualPayService $pay=null){$this->pay=$pay??new VirtualPayService();}
    private function original(array $i): array {return VirtualPayConfigService::version((int)$i['tenant_id'],(int)$i['config_version_id']);}
    public function inspect(array $i): ?array
    {
        if((int)$i['order_type']===7||$i['wxpay_order_id']==='')return null;
        $v=$this->original($i);$managed=$this->pay->gateway()->request($v,'/wxa/sec/order/is_trade_managed',['appid'=>$i['app_id']],false);
        if(!array_key_exists('is_trade_managed',$managed))throw new \RuntimeException('发货管理状态暂未查明');
        if(!$managed['is_trade_managed'])return null;
        $result=$this->pay->gateway()->request($v,'/wxa/sec/order/get_order',['transaction_id'=>$i['wxpay_order_id']],false);$o=$result['order']??null;
        if(!is_array($o)||($o['transaction_id']??'')!==$i['wxpay_order_id'])throw new \RuntimeException('发货订单编号核对失败');
        if(isset($o['openid'])&&$o['openid']!==$i['openid'])throw new \RuntimeException('发货订单付款人不匹配');return $o;
    }
    public function sync(int $id): bool
    {
        $i=Db::name('virtual_pay_intent')->where('id',$id)->find();if(!$i||$i['delivery_status']!=='granted'||$i['refund_status']!=='none')throw new \RuntimeException('订单尚未交付或正在退款，不能录入发货');
        $o=$this->inspect($i);
        if($o===null){Db::name('virtual_pay_intent')->where('id',$id)->update(['general_shipping_status'=>'not_applicable','updated_at'=>time()]);return true;}
        if(!empty($o['shipping']['finish_shipping'])||in_array((int)($o['order_state']??0),[2,3,4,6],true)){
            Db::name('virtual_pay_intent')->where('id',$id)->update(['general_shipping_status'=>(int)$o['order_state']===3?'received':'confirmed','updated_at'=>time()]);return true;
        }
        if((int)($o['order_state']??0)!==1)throw new \RuntimeException('发货订单状态需要人工核对');
        $mayUpload=Db::transaction(function()use($id){$current=Db::name('virtual_pay_intent')->where('id',$id)->lock(true)->find();if($current['general_shipping_status']!=='pending'||$current['refund_status']!=='none')return false;Db::name('virtual_pay_intent')->where('id',$id)->update(['general_shipping_status'=>'submitting','updated_at'=>time()]);return true;});
        if(!$mayUpload)throw new \RuntimeException('发货结果尚未确认，系统将继续查询，请勿重复上传');
        try{
            $this->pay->gateway()->request($this->original($i),'/wxa/sec/order/upload_shipping_info',[
                'order_key'=>['order_number_type'=>2,'transaction_id'=>$i['wxpay_order_id']],
                'logistics_type'=>3,'delivery_mode'=>1,'shipping_list'=>[['item_desc'=>$i['biz_type']==='recharge'?'算力套餐权益已到账':'会员套餐权益已生效']],
                'upload_time'=>gmdate(DATE_RFC3339),'payer'=>['openid'=>$i['openid']],
            ],false);
            Db::name('virtual_pay_intent')->where('id',$id)->update(['general_shipping_status'=>'confirming','updated_at'=>time()]);return false;
        }catch(\Throwable $e){Db::name('virtual_pay_intent')->where('id',$id)->update(['general_shipping_status'=>'unknown','updated_at'=>time()]);throw $e;}
    }
    public function confirmation(array $i): array
    {
        if($i['delivery_status']!=='granted'||$i['refund_status']!=='none')return ['confirm_receipt'=>false];
        $o=$this->inspect($i);if(!$o)return ['confirm_receipt'=>false];
        return ['confirm_receipt'=>(int)$o['order_state']===2,'receipt_received'=>in_array((int)$o['order_state'],[3,4,6],true),'transaction_id'=>$i['wxpay_order_id']];
    }
}
