<?php
require __DIR__.'/Support/OemDisposable.php';require __DIR__.'/Support/OemFixtures.php';require __DIR__.'/Support/VirtualPayFixture.php';
use think\facade\Db;
use app\common\service\virtualpay\VirtualPayService as Pay;
use app\common\service\virtualpay\VirtualPayGateway as Gateway;
use app\common\service\virtualpay\VirtualPaySessionService as Session;
use app\common\service\virtualpay\VirtualPayNotifyService as Notify;
use app\common\service\virtualpay\MiniappShippingService as Shipping;
use app\common\service\virtualpay\VirtualPayDeliveryService as Delivery;
use app\common\service\promotion\PackagePricingService as Price;
Db::startTrans();try{
 $f=virtualFixture();$query=[];$shippingState=1;$uploads=0;$calls=[];
 $g=new Gateway(static function($method,$path,$params,$body)use(&$f,&$query,&$shippingState,&$uploads,&$calls){$calls[]=$path;
  if($path==='/sns/jscode2session')return ['openid'=>$f['openid'],'session_key'=>'fixture-session'];
  if($path==='/xpay/query_order')return $query;
  if($path==='/wxa/sec/order/is_trade_managed')return ['errcode'=>0,'is_trade_managed'=>true];
  if($path==='/wxa/sec/order/get_order')return ['errcode'=>0,'order'=>['transaction_id'=>$query['order']['wxpay_order_id'],'order_state'=>$shippingState]];
  if($path==='/wxa/sec/order/upload_shipping_info'){$uploads++;$p=json_decode($body,true);oemCheck($p['logistics_type']===3&&$p['delivery_mode']===1&&!isset($p['shipping_list'][0]['tracking_no']),'virtual unified shipping without fake courier');throw new RuntimeException('Simulated lost response');}
  return ['errcode'=>0];
 });
 $pay=new Pay($g);$session=(new Session($g))->exchange($f['tenant'],$f['user'],'code');$pre=$pay->prepay($f['tenant'],$f['user'],1,'recharge',$f['order']['order_id'],$session,'android');$i=Db::name('virtual_pay_intent')->where('out_trade_no',$pre['out_trade_no'])->find();$query=virtualQuery($i);
 // A signed but incorrect transaction identifier must not grant before rejection.
 $bad=virtualNotice($i);$bad['WeChatPayInfo']=['TransactionId'=>'wrong-transaction'];oemReject(fn()=>(new Notify($pay))->event($f['tenant'],$f['app'],$bad),'wrong transaction');oemCheck(Db::name('user')->where('id',$f['user'])->value('user_money')==='0.00','invalid transaction rejected before grant');
 $user=Db::name('user')->where('id',$f['user'])->find();Db::name('user')->where('id',$f['user'])->delete();
 oemReject(fn()=>$pay->reconcile($i),'real delivery failure');
 oemCheck(Db::name('virtual_pay_intent')->where('id',$i['id'])->value('status')==='paid','paid proof persists after failed grant');
 oemCheck(Db::name('tenant_package_price_snapshot')->where(['tenant_id'=>$f['tenant'],'order_id'=>$f['order']['order_id']])->value('status')==='pending','failed grant does not consume claim');
 Db::name('user')->insert($user);$pay->reconcile($i);$pay->reconcile($i);oemCheck(Db::name('user')->where('id',$f['user'])->value('user_money')==='100.00','resume same paid intent grants once');
 oemCheck(! (new Delivery($pay))->syncXpay($i['id']),'accepted provide-goods request remains confirming');$query['order']['status']=4;oemCheck((new Delivery($pay))->syncXpay($i['id']),'query confirms xpay delivery');
 $shipping=new Shipping($pay);oemReject(fn()=>$shipping->sync($i['id']),'shipping network ambiguity');oemReject(fn()=>$shipping->sync($i['id']),'unknown upload only queries');oemCheck($uploads===1,'unknown shipping never uploads twice');
 $shippingState=2;oemCheck($shipping->sync($i['id']),'recovered query confirms general shipping');$current=Db::name('virtual_pay_intent')->where('id',$i['id'])->find();oemCheck($shipping->confirmation($current)['confirm_receipt'],'receipt component allowed only real shipped Wx transaction');
 Db::name('virtual_pay_intent')->where('id',$i['id'])->update(['order_type'=>7,'wxpay_order_id'=>'']);$before=count($calls);oemCheck($shipping->sync($i['id']),'Apple excluded from generic shipping');oemCheck(count($calls)===$before,'Apple exclusion does not fake Wx order');
 // Closed business order: late payment remains recorded and frozen for refund, never reactivated.
 $f=virtualFixture();$session=(new Session($g))->exchange($f['tenant'],$f['user'],'code');$pre=$pay->prepay($f['tenant'],$f['user'],1,'recharge',$f['order']['order_id'],$session,'android');$late=Db::name('virtual_pay_intent')->where('out_trade_no',$pre['out_trade_no'])->find();$query=virtualQuery($late,6);$pay->reconcile($late);Price::closeConfirmed($f['tenant'],$f['user'],'recharge',$f['order']['order_id'],'closed');$query=virtualQuery($late,2);oemReject(fn()=>$pay->reconcile($late),'late payment cannot grant');
 oemCheck(Db::name('virtual_pay_receipt')->where('intent_id',$late['id'])->value('kind')==='late','late funds recorded');oemCheck(Db::name('virtual_pay_intent')->where('id',$late['id'])->value('refund_status')==='required','late funds queued for refund');oemCheck(Db::name('user')->where('id',$f['user'])->value('user_money')==='0.00','late receipt never grants');
 echo "PASS durable paid fault/recovery, pre-grant validation, XPay compensation, unknown shipping retention, Apple exclusion and late receipt\n";
}finally{Db::rollback();}
