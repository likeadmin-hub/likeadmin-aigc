<?php
/** No merchant calls: real gateway, SDK error storage and isolated SQLite identity/config records. */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use app\common\enum\AdminTerminalEnum;
use app\common\enum\user\UserTerminalEnum;
use app\common\service\pay\WeChatPayService;
use app\common\service\promotion\PackagePaymentGateway;
use think\facade\Config;
use think\facade\Db;
(new think\App(dirname(__DIR__, 2)))->initialize();
Config::set(['default'=>'payment_test','connections'=>['payment_test'=>['type'=>'sqlite','database'=>':memory:','prefix'=>'la_','fields_strict'=>true,'fields_cache'=>false]]], 'database');
request()->tenantId=1; request()->source=AdminTerminalEnum::USER;
foreach ([
    'tenant'=>'id INTEGER PRIMARY KEY, sn TEXT, tactics INTEGER DEFAULT 0, delete_time INTEGER',
    'tenant_config'=>'id INTEGER PRIMARY KEY, tenant_id INTEGER, type TEXT, name TEXT, value TEXT',
    'tenant_pay_config'=>'id INTEGER PRIMARY KEY, tenant_id INTEGER, pay_way INTEGER, config TEXT',
    'user_auth'=>"id INTEGER PRIMARY KEY, tenant_id INTEGER, user_id INTEGER, terminal INTEGER, appid TEXT DEFAULT '', openid TEXT",
] as $name=>$fields) Db::execute("CREATE TABLE la_{$name} ({$fields})");
Db::name('tenant')->insert(['id'=>1,'sn'=>'one']);
Db::name('tenant_config')->insert(['tenant_id'=>1,'type'=>'mnp_setting','name'=>'app_id','value'=>'wx-current']);
Db::name('tenant_pay_config')->insert(['tenant_id'=>1,'pay_way'=>2,'config'=>'{}']);
Db::name('user_auth')->insertAll([
    ['tenant_id'=>1,'user_id'=>7,'terminal'=>1,'appid'=>'wx-old','openid'=>'openid-old'],
    ['tenant_id'=>1,'user_id'=>7,'terminal'=>1,'appid'=>'wx-current','openid'=>'openid-current'],
]);
function checkPayment(bool $ok,string $label):void {if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL;}
$failures=[];
try {
    $sdk=new WeChatPayService(UserTerminalEnum::WECHAT_MMP,7);
    $auth=new ReflectionProperty($sdk,'auth');$auth->setAccessible(true);
    checkPayment($auth->getValue($sdk)['openid']==='openid-current','JSAPI uses openid belonging to current AppID');
} catch(Throwable $e) {$failures[]=$e->getMessage();}
try {
    Db::name('user_auth')->where('user_id',7)->delete();
    Db::name('user_auth')->insert(['tenant_id'=>1,'user_id'=>7,'terminal'=>1,'appid'=>'','openid'=>'legacy-openid']);
    $sdk=new WeChatPayService(UserTerminalEnum::WECHAT_MMP,7);
    $auth=new ReflectionProperty($sdk,'auth');$auth->setAccessible(true);
    checkPayment($auth->getValue($sdk)['openid']==='legacy-openid','legacy empty-AppID bindings remain payable');
    Db::name('user_auth')->where('user_id',7)->update(['appid'=>'wx-other']);
    $sdk=new WeChatPayService(UserTerminalEnum::WECHAT_MMP,7);
    checkPayment($sdk->pay('membership',['pay_sn'=>'offline-test','order_amount'=>'1.00'])===false,'wrong AppID binding stops before external SDK request');
    checkPayment(str_contains($sdk->getError(),'重新授权登录'),'missing current identity explains reauthorization');
} catch(Throwable $e) {$failures[]=$e->getMessage();}
$gateway=new PackagePaymentGateway();
$sdk=new class extends WeChatPayService {
    public function __construct() {}
    public function pay($from,$order) {return false;}
};
$sdk->setError('微信:APPID_MCHID_NOT_MATCH-appid wx-secret and mchid 123456 do not match');
$clients=new ReflectionProperty($gateway,'clients');$clients->setAccessible(true);$clients->setValue($gateway,['1:2:1:7'=>$sdk]);
try {
    $gateway->call('prepay',2,1,7,['from'=>'membership','order'=>[]]);
    $failures[]='FAIL gateway must reject failed prepay';
} catch(RuntimeException $e) {
    try {checkPayment(str_contains($e->getMessage(),'APPID_MCHID_NOT_MATCH')&&str_contains($e->getMessage(),'商户号')&&!str_contains($e->getMessage(),'wx-secret')&&!str_contains($e->getMessage(),'123456'),'gateway preserves actionable channel cause without payment identity values');}catch(Throwable $failure){$failures[]=$failure->getMessage();}
}
try {
    $message=\app\common\service\promotion\PackagePaymentError::message('TLS error /private/cert.pem private-token=secret-value',2);
    checkPayment(!str_contains($message,'private-token')&&!str_contains($message,'cert.pem'),'unknown SDK errors never disclose raw details');
    $message=\app\common\service\promotion\PackagePaymentError::message('微信:OPENID_MISMATCH-payer_openid=secret',2);
    checkPayment(str_contains($message,'重新授权登录')&&!str_contains($message,'payer_openid'),'identity rejection provides safe recovery action');
} catch(Throwable $e) {$failures[]=$e->getMessage();}
foreach($failures as $failure)fwrite(STDERR,$failure.PHP_EOL);
exit($failures?1:0);
