<?php
declare(strict_types=1);
require __DIR__.'/Support/OemDisposable.php';require __DIR__.'/Support/OemFixtures.php';
use think\facade\Db;
use app\common\service\brand\BrandChannelGateway;
use app\common\service\brand\TenantBrandService as Brand;
use app\api\controller\brand\PaymentController;
use EasyWeChat\Kernel\Support\AesGcm;
use EasyWeChat\Pay\Application;
use Nyholm\Psr7\ServerRequest;
oemCheck(method_exists(BrandChannelGateway::class,'verifyWechatNotification'),'Callback explicit signature validation missing');
class SignedNotificationFixture extends BrandChannelGateway {
 public Application $application;public string $trustedCertificate;
 public function wechat(array $context,string $notify=''): Application{return $this->application;}
 protected function platformCertificates(Application $app): array{return [$this->trustedCertificate];}
}
Db::startTrans();try {
 $f=oemFixture();$id=Brand::createBrandOrder($f['seller'],$f['user'],4,$f['params'])['order_id'];oemPaid($f,$id);
 $intent=Db::name('tenant_brand_payment_intent')->where(['order_id'=>$id,'order_from'=>Brand::FROM_ORDER])->find();
 // A new unpaid intent/order avoids relying on a duplicate receipt to hide a bypass.
 Db::name('tenant_brand_payment')->where('order_id',$id)->delete();Db::name('tenant_brand_order')->where('id',$id)->update(['pay_status'=>0,'workflow_state'=>'awaiting_payment']);
 $key=openssl_pkey_new(['private_key_bits'=>2048]);openssl_pkey_export($key,$private);$csr=openssl_csr_new(['commonName'=>'trusted-channel-fixture'],$key);$cert=openssl_csr_sign($csr,null,$key,1,[],123);openssl_x509_export($cert,$pem);$serial=strtoupper(openssl_x509_parse($cert)['serialNumberHex']);
 $untrustedKey=openssl_pkey_new(['private_key_bits'=>2048]);
 $gate=new SignedNotificationFixture();$gate->trustedCertificate=$pem;$secret=str_repeat('s',32);$gate->application=new Application(['mch_id'=>'123','private_key'=>$private,'certificate'=>$pem,'secret_key'=>$secret]);app()->instance(BrandChannelGateway::class,$gate);
 $payload=['out_trade_no'=>$intent['pay_sn'],'mchid'=>'fixture-merchant','appid'=>'fixture-app','transaction_id'=>'signed-trade','trade_state'=>'SUCCESS','amount'=>['total'=>3101,'currency'=>'CNY']];
 $body=json_encode(['event_type'=>'TRANSACTION.SUCCESS','resource'=>['algorithm'=>'AEAD_AES_256_GCM','nonce'=>'123456789012','associated_data'=>'transaction','ciphertext'=>AesGcm::encrypt(json_encode($payload),$secret,'123456789012','transaction')]]);
 request()->withGet(['intent_id'=>$intent['id']]);$controller=new PaymentController(app());
 foreach(['missing','wrong','old','future','merchant-key','untrusted'] as $variant){
  $timestamp=(string)(time()+($variant==='old'?-601:($variant==='future'?601:0)));$headers=['Wechatpay-Timestamp'=>$timestamp,'Wechatpay-Nonce'=>'test-nonce','Wechatpay-Serial'=>$serial];openssl_sign($timestamp."\ntest-nonce\n".$body."\n",$signature,$variant==='merchant-key'?$untrustedKey:$key,OPENSSL_ALGO_SHA256);$headers['Wechatpay-Signature']=$variant==='wrong'?'forged':base64_encode($signature);
  if($variant==='missing')unset($headers['Wechatpay-Signature']);if($variant==='untrusted')$headers['Wechatpay-Serial']='UNTRUSTED';
  $gate->application->setRequest(new ServerRequest('POST','https://fixture/notify',$headers,$body));$response=$controller->notifyWechat();oemCheck($response->getCode()===500,$variant.' callback was accepted');oemCheck(Db::name('tenant_brand_payment')->where('order_id',$id)->count()===0,$variant.' callback wrote money');oemCheck((int)Db::name('tenant_brand_order')->where('id',$id)->value('pay_status')===0,$variant.' callback changed order');
 }
 $timestamp=(string)time();openssl_sign($timestamp."\ntest-nonce\n".$body."\n",$signature,$key,OPENSSL_ALGO_SHA256);$gate->application->setRequest(new ServerRequest('POST','https://fixture/notify',['Wechatpay-Timestamp'=>$timestamp,'Wechatpay-Nonce'=>'test-nonce','Wechatpay-Serial'=>$serial,'Wechatpay-Signature'=>base64_encode($signature)],$body));
 $response=$controller->notifyWechat();oemCheck($response->getStatusCode()===200,'Trusted signed callback rejected');oemCheck(Db::name('tenant_brand_payment')->where('order_id',$id)->count()===1,'Signed callback records one payment');oemCheck(Db::name('tenant_brand_order')->where('id',$id)->value('workflow_state')==='tenant_review','Signed callback never provisions');
 echo "PASS actual callback controller rejects missing/wrong/stale/future/merchant-key/untrusted signatures before money; valid signed encrypted callback pays once\n";
}finally{Db::rollback();}
