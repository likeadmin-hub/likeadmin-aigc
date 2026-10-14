<?php
require __DIR__.'/Support/OemDisposable.php';require __DIR__.'/Support/OemFixtures.php';require __DIR__.'/Support/VirtualPayFixture.php';
use think\facade\Db;
use app\common\service\virtualpay\VirtualPayConfigService as Config;
use app\common\service\virtualpay\VirtualPayService as Pay;
use app\common\service\virtualpay\VirtualPayGateway as Gateway;
use app\common\service\virtualpay\VirtualPaySessionService as Session;
use app\common\logic\PaymentLogic;
Db::startTrans();try {
    $f=virtualFixture();$detail=Config::detail($f['tenant'],0);
    oemCheck(($detail['enabled_platforms']??null)===['ios','android'],'legacy enabled config preserves both platforms');
    $gateway=new Gateway(static fn($method,$path,$q,$body)=>['openid'=>$f['openid'],'session_key'=>'fixture-session']);
    $session=(new Session($gateway))->exchange($f['tenant'],$f['user'],'code');$pay=new Pay($gateway);
    $ways=static fn($platform)=>PaymentLogic::getPayWay($f['user'],1,['tenant_id'=>$f['tenant'],'from'=>'recharge','order_id'=>$f['order']['order_id'],'platform'=>$platform]);
    foreach ([['ios'],['android'],['ios','android'],[]] as $platforms) {
        $d=Config::save($f['tenant'],0,['enabled_platforms'=>$platforms,'app_id'=>'']);
        oemCheck($d['enabled_platforms']===$platforms && $d['enabled']===(int)(bool)$platforms,'selection is persisted and determines enabled');
        oemCheck($d['app_id']===$f['app'],'blank AppID resolves from channel');
        foreach (['ios','android'] as $platform) {
            $result=$ways($platform);oemCheck(is_array($result),'pay way selection succeeds');
            oemCheck(in_array(4,array_column($result['lists'],'pay_way'),true)===in_array($platform,$platforms,true),'only enabled platform offers virtual payment');
            if(!in_array($platform,$platforms,true)) oemReject(fn()=>$pay->prepay($f['tenant'],$f['user'],1,'recharge',$f['order']['order_id'],$session,$platform,0),'disabled platform cannot prepay directly');
        }
    }
    oemReject(fn()=>Config::save($f['tenant'],0,['enabled_platforms'=>['windows']]),'unknown platform rejected');
    oemReject(fn()=>Config::save($f['tenant'],0,['enabled_platforms'=>'ios']),'invalid platform list rejected');
    oemReject(fn()=>Config::save($f['tenant'],0,['enabled_platforms'=>[['ios']]]),'nested platform values rejected');
    oemReject(fn()=>Config::save($f['tenant'],0,['enabled_platforms'=>['ios'],'app_id'=>'wx-other']),'AppID override rejected');
    Config::save($f['tenant'],0,['enabled_platforms'=>['ios']]);
    Config::save($f['tenant'],0,['enabled'=>1]);oemCheck(Config::detail($f['tenant'],0)['enabled_platforms']===['ios'],'legacy save must not broaden existing selection');
    $missing=$ways('');oemCheck(in_array(4,array_column($missing['lists'],'pay_way'),true),'old clients without platform cannot bypass virtual payment policy');
    $p=$pay->prepay($f['tenant'],$f['user'],1,'recharge',$f['order']['order_id'],$session,'ios',0);
    $intent=Db::name('virtual_pay_intent')->where('out_trade_no',$p['out_trade_no'])->find();
    Config::save($f['tenant'],0,['enabled_platforms'=>[]]);
    $locked=$ways('ios');oemCheck($locked['locked_channel']===4,'existing order remains bound to original channel');
    $query=virtualQuery($intent,2,true);$verified=new Pay(new Gateway(static fn()=> $query));$verified->reconcile($intent);
    oemCheck($verified->status($f['tenant'],$f['user'],'recharge',$f['order']['order_id'])['payment_status']==='paid','disabled platform still reconciles existing payment');
    Db::name('tenant_config')->where(['tenant_id'=>$f['tenant'],'type'=>'mnp_setting','name'=>'app_id'])->update(['value'=>'wx-replaced']);
    oemCheck(Config::detail($f['tenant'],0)['enabled_platforms']===[],'new channel app never reuses old enablement');
    $normal=virtualFixture();Config::save($normal['tenant'],0,['enabled_platforms'=>['ios']]);$ordinaryCalls=0;
    app()->instance(\app\common\service\promotion\PackagePaymentGateway::class,new \app\common\service\promotion\PackagePaymentGateway(static function($action)use(&$ordinaryCalls){$ordinaryCalls++;return $action==='context'?['app_id'=>'normal-app','merchant'=>'merchant','fingerprint'=>'fixture']:['pay_way'=>2,'config'=>'fixture-prepay'];}));
    $order=Pay::order($normal['tenant'],$normal['user'],'recharge',$normal['order']['order_id']);
    oemCheck(PaymentLogic::pay(2,'recharge',$order,1,'/','ios')===false,'enabled iOS cannot use ordinary prepay');
    oemCheck(PaymentLogic::pay(2,'recharge',$order,1,'/')===false,'omitted platform cannot bypass virtual-only policy');
    oemCheck($ordinaryCalls===0,'blocked platforms never contact ordinary gateway');
    oemCheck(is_array(PaymentLogic::pay(2,'recharge',$order,1,'/','android')),'disabled Android retains ordinary configured payment');
    oemCheck($ordinaryCalls>0,'allowed ordinary flow reaches gateway');
    echo "PASS platform selection, automatic AppID, legacy compatibility, entry enforcement and existing order reconciliation\n";
}finally{Db::rollback();}
