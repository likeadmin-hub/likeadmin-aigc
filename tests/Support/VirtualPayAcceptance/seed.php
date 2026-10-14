<?php
require dirname(__DIR__).'/OemDisposable.php';require dirname(__DIR__).'/OemFixtures.php';require dirname(__DIR__).'/VirtualPayFixture.php';
use think\facade\Db;
use app\common\service\license\SignedLicenseProtocol as Protocol;
use app\common\service\update\UpdateLicenseService;
use app\common\service\update\UpdateSourceClient;
if(!str_starts_with((string)getenv('PHP_DATABASE_DATABASE'),'oem_e2e_virtual_'))throw new RuntimeException('Own virtual acceptance DB required');
// Actual signed license and bound cache, with a test issuer. No gate bypass.
request()->withServer(array_merge(request()->server(),['HTTP_HOST'=>'127.0.0.1:19875']));request()->source=app\common\enum\AdminTerminalEnum::PLATFORM;
UpdateSourceClient::getSource();$key=openssl_pkey_new(['private_key_bits'=>2048]);$public=openssl_pkey_get_details($key)['key'];
Db::name('update_source')->where('status',1)->update(['status'=>0]);Db::name('update_source')->insert(['name'=>'Disposable issuer','base_url'=>'http://issuer.invalid','license_key'=>'fixture','online_base_url'=>'http://issuer.invalid','online_license_key'=>'fixture','dev_mode'=>1,'public_key'=>$public,'status'=>1]);
$license=new UpdateLicenseService();$machine=$license->machineCode();$payload=(object)['license_no'=>'LIC-virtual-local','version'=>1,'product_code'=>UpdateSourceClient::PRODUCT_CODE,'domains'=>[$machine['domain']],'machine_fingerprint_hash'=>$machine['machine_fingerprint_hash'],'license_type'=>'commercial','is_perpetual'=>true,'expires_at'=>0,'issuer'=>(object)['platform_name'=>'Disposable issuer','copyright'=>[]]];
openssl_sign(Protocol::json($payload),$signature,$key,OPENSSL_ALGO_SHA256);$license->storeCertificate(Protocol::json((object)['payload'=>$payload,'signature'=>base64_encode($signature),'key_id'=>'platform-default','algorithm'=>'RSA-SHA256']));
$access=new class($key) extends app\common\service\license\SiteLicenseAccessService {
 public function __construct(private $key){}
 protected function send(string $endpoint,array $c,string $nonce):string{
  $now=time();$data=['protocol_version'=>1,'license_no'=>$c['license_no'],'license_version'=>$c['license_version'],'license_type'=>$c['license_type'],'site_status'=>'active','domains'=>[$c['domain']],'machine_fingerprint_hash'=>$c['machine_fingerprint_hash'],'issued_at'=>$now,'expires_at'=>$now+86400,'refresh_after'=>$now+3600,'request_nonce'=>$nonce,'apps'=>[]];$body=(object)['code'=>1,'msg'=>'fixture','data'=>$data,'request_id'=>'fixture','server_time'=>$now];openssl_sign(Protocol::json($body),$signature,$this->key,OPENSSL_ALGO_SHA256);$body->signature=base64_encode($signature);return Protocol::json($body);
 }
};oemCheck($access->refresh(true)['can_customize'],'signed commercial qualification');
$password='FixturePassword1!';$hash=create_password($password,config('project.unique_identification'));$fixtures=[];
for($n=0;$n<2;$n++){
 $f=virtualFixture();$pseudo=['order_kind'=>'new','child_tenant_id'=>$f['tenant'],'admin_account'=>'virtualseller','admin_password_hash'=>$hash,'order_sn'=>'virtual-local-'.$n,'brand_snapshot'=>json_encode(['name'=>'虚拟支付验收'.$n,'contact'=>'13300000000'])];$provision=new app\common\service\brand\BrandTenantProvisioner();request()->tenantId=null;foreach(['initialize_admin','initialize_services','initialize_brand'] as $step)$provision->step($step,$pseudo);request()->tenantId=$f['tenant'];
 Db::name('user')->where('id',$f['user'])->update(['account'=>'virtualcustomer'.$f['tenant'],'password'=>$hash,'nickname'=>'验收客户'.$n,'channel'=>4]);$f['account']='virtualcustomer'.$f['tenant'];
 foreach(['status'=>1,'min_amount'=>1] as $name=>$v)Db::name('tenant_config')->insert(['tenant_id'=>$f['tenant'],'type'=>'recharge','name'=>$name,'value'=>(string)$v]);
 Db::name('tenant_config')->insert(['tenant_id'=>$f['tenant'],'type'=>'login','name'=>'login_way','value'=>'[1]']);
 Db::name('tenant_pay_way')->where('tenant_id',$f['tenant'])->update(['status'=>1]);
 app\common\service\promotion\PackagePricingService::closeConfirmed($f['tenant'],$f['user'],'recharge',$f['order']['order_id'],'closed');
 $m=app\common\service\membership\MembershipService::savePlan($f['tenant'],['name'=>'验收会员','monthly_price'=>'39.00','duration_months'=>1,'features'=>['验收权益'],'bonus_points'=>'5.00','app_codes'=>[]]);$f['member']=(int)$m->id;
 app\common\service\promotion\PackagePromotionService::save($f['tenant'],'membership',$f['member'],['enabled'=>1,'rule_new'=>1,'rule_all'=>1,'hours'=>48,'discount_prices'=>['package'=>'19.90']]);
 foreach(['regular'=>'39.00','discount'=>'19.90'] as $variant=>$price)app\common\service\virtualpay\VirtualPayConfigService::saveProduct($f['tenant'],0,['from'=>'membership','package_id'=>$f['member'],'cycle'=>'package','variant'=>$variant,'product_id'=>'member-'.$variant,'price'=>$price]);
 $fixtures[]=$f;
}
if(!Db::name('admin')->where('account','virtualplatform')->find())app\common\model\auth\Admin::create(['account'=>'virtualplatform','password'=>$hash,'name'=>'虚拟支付验收平台','root'=>1,'disable'=>0]);
file_put_contents(root_path().'runtime/virtual-fixture.json',json_encode(['fixtures'=>$fixtures,'password'=>$password],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));echo "PASS disposable identities, real menus and signed license seeded\n";
