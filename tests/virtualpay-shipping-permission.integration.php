<?php
require __DIR__.'/Support/OemDisposable.php';
require __DIR__.'/Support/OemFixtures.php';
require __DIR__.'/Support/VirtualPayFixture.php';
use think\facade\Db;
use app\common\service\virtualpay\{VirtualPayService as Pay,VirtualPayGateway as Gateway,VirtualPaySessionService as Session,VirtualPayDeliveryService as Delivery,VirtualPayMaintenanceService as Maintain,VirtualPayAdminService as Admin};
Db::startTrans();try {
    // This test catches treating a permanent capability denial as a retryable unpaid order.
    Db::name('virtual_pay_work')->where('id','>',0)->update(['status'=>'done']);
    $f=virtualFixture();$query=[];$denied=true;$probes=0;$uploads=0;
    $gateway=new Gateway(static function($method,$path,$params,$body)use(&$f,&$query,&$denied,&$probes,&$uploads){
        if($path==='/sns/jscode2session')return ['openid'=>$f['openid'],'session_key'=>'fixture-session'];
        if($path==='/xpay/query_order')return $query;
        if($path==='/wxa/sec/order/is_trade_managed'){$probes++;return $denied?['errcode'=>48001,'errmsg'=>'api unauthorized']:['errcode'=>0,'is_trade_managed'=>true];}
        if($path==='/wxa/sec/order/get_order')return ['errcode'=>0,'order'=>['transaction_id'=>$query['order']['wxpay_order_id'],'order_state'=>2]];
        if($path==='/wxa/sec/order/upload_shipping_info')$uploads++;
        return ['errcode'=>0];
    });
    $pay=new Pay($gateway);$session=(new Session($gateway))->exchange($f['tenant'],$f['user'],'code');
    $pre=$pay->prepay($f['tenant'],$f['user'],1,'recharge',$f['order']['order_id'],$session,'android');
    $intent=Db::name('virtual_pay_intent')->where('out_trade_no',$pre['out_trade_no'])->find();$query=virtualQuery($intent,4);
    $pay->reconcile($intent);(new Delivery($pay))->syncXpay($intent['id']);
    $logs=Db::name('user_account_log')->where(['tenant_id'=>$f['tenant'],'user_id'=>$f['user']])->count();
    Db::name('virtual_pay_work')->where('intent_id',$intent['id'])->update(['status'=>'done']);
    Db::name('virtual_pay_work')->where(['intent_id'=>$intent['id'],'kind'=>'general_shipping'])->update(['status'=>'pending','next_at'=>0]);
    $maintain=new Maintain($pay);$maintain->run(1,1);
    $work=Db::name('virtual_pay_work')->where(['intent_id'=>$intent['id'],'kind'=>'general_shipping'])->find();
    oemCheck($work['status']==='manual'&&(int)$work['attempts']===1,'48001 must stop automatic retries immediately');
    $current=Db::name('virtual_pay_intent')->find($intent['id']);
    oemCheck($current['general_shipping_status']==='manual','denied capability must not become confirmed or not_applicable');
    oemCheck($current['delivery_status']==='granted'&&$current['xpay_shipping_status']==='confirmed','general permission must not regress real delivery');
    oemCheck(str_contains($current['last_error'],'48001'),'operator retains exact permission diagnosis');
    $status=$pay->status($f['tenant'],$f['user'],'recharge',$f['order']['order_id'],false);
    oemCheck($status['message']==='权益已到账，微信虚拟支付已发货','customer must not be told paid entitlement is still unconfirmed');
    $admin=Admin::orders($f['tenant']);oemCheck(str_contains($admin['lists'][0]['last_error'],'通用发货接口未授权'),'operator sees actionable independent capability error');
    $before=$probes;$maintain->run(1,1);oemCheck($probes===$before,'manual permission task must not be polled automatically');
    oemCheck($uploads===0&&Db::name('user_account_log')->where(['tenant_id'=>$f['tenant'],'user_id'=>$f['user']])->count()===$logs,'no repeated shipping or credit');
    $denied=false;Admin::retry($f['tenant'],0,$intent['id']);$maintain->run(5,2);
    $current=Db::name('virtual_pay_intent')->find($intent['id']);
    oemCheck($current['general_shipping_status']==='confirmed'&&$current['last_error']==='','explicit retry after permission repair clears stale permission error');
    oemCheck($uploads===0,'already shipped generic order must not be uploaded again');
    // A prior upload with a lost response must remain query-only even if permissions later disappear.
    Db::name('virtual_pay_intent')->where('id',$intent['id'])->update(['general_shipping_status'=>'unknown']);
    $denied=true;Admin::retry($f['tenant'],0,$intent['id']);$maintain->run(5,2);
    oemCheck(Db::name('virtual_pay_intent')->where('id',$intent['id'])->value('general_shipping_status')==='unknown','permission denial must preserve ambiguous prior upload');
    Admin::retry($f['tenant'],0,$intent['id']);
    oemCheck(Db::name('virtual_pay_intent')->where('id',$intent['id'])->value('general_shipping_status')==='unknown','explicit retry must not reset unknown upload to pending');
    $denied=false;$maintain->run(5,2);oemCheck($uploads===0,'recovering permission never repeats an ambiguous shipment');
    echo "PASS permission denial is terminal for automatic retries, preserves paid XPay rights, has actionable messages and recovers after explicit retry\n";
}finally{Db::rollback();}
