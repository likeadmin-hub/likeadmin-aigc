<?php
use app\common\service\brand\BrandPaymentGateway;
final class OemFakeGateway implements BrandPaymentGateway {
    public array $receipts=[];
    public int $queries=0;
    public function context(string $from,array $order,int $channel): array {
        return ['scope'=>$from==='tenant_brand_quota'?'platform':'tenant','tenant_id'=>(int)$order['tenant_id'],'channel'=>$channel,'merchant_id'=>$from==='tenant_brand_quota'?'platform-A':'seller-A','app_id'=>'app-A','seller_id'=>'','terminal'=>5];
    }
    public function prepay(array $intent,string $redirect): array {
        if ((int)$intent['amount_cents']!==3101 && (int)$intent['amount_cents']!==1999) throw new RuntimeException('Wrong gateway charge');
        return ['pay_way'=>(int)$intent['channel'],'config'=>(int)$intent['channel']===2?'weixin://wxpay/bizpayurl?pr=fixture':'<form action="https://openapi.alipay.com/gateway.do"></form>'];
    }
    public function query(array $intent): ?array { $this->queries++; return $this->receipts[$intent['pay_sn']]??null; }
}
