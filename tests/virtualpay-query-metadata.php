<?php
declare(strict_types=1);

namespace app\common\service\virtualpay {
    // Isolate credential storage; query validation below uses the real payment service.
    final class VirtualPayConfigService
    {
        public static function version(int $tenant, int $id): array { return []; }
    }
}

namespace {
    require dirname(__DIR__).'/app/common/service/virtualpay/VirtualPayPolicy.php';
    require dirname(__DIR__).'/app/common/service/virtualpay/VirtualPayGateway.php';
    require dirname(__DIR__).'/app/common/service/virtualpay/VirtualPayService.php';
    use app\common\service\virtualpay\{VirtualPayGateway, VirtualPayService};

    $intent=['tenant_id'=>1,'config_version_id'=>1,'openid'=>'fixture-openid','env'=>0,
        'out_trade_no'=>'Vfixture123','amount_cents'=>3000,'attach'=>'vp:Vfixture123'];
    $order=['order_id'=>'Vfixture123','env_type'=>1,'status'=>3,'order_fee'=>3000,
        'paid_fee'=>3000,'wx_order_id'=>'fixture-internal','wxpay_order_id'=>'fixture-payment',
        'biz_meta'=>'{"attach":"vp:Vfixture123"}'];
    $gateway=new class extends VirtualPayGateway {
        public array $order=[];
        public function request(array $v,string $path,array $payload,bool $signed=true): array
        {
            if ($path!=='/xpay/query_order'||$payload!==['openid'=>'fixture-openid','env'=>0,'order_id'=>'Vfixture123']) {
                throw new \LogicException('Unexpected channel request');
            }
            return ['errcode'=>0,'order'=>$this->order];
        }
    };
    $pay=new VirtualPayService($gateway);
    foreach (['{"attach":"vp:Vfixture123"}','vp:Vfixture123','',null] as $meta) {
        $gateway->order=$order;
        if ($meta===null) unset($gateway->order['biz_meta']); else $gateway->order['biz_meta']=$meta;
        if ($pay->query($intent)!==$gateway->order) throw new \RuntimeException('Valid payment was rejected or modified');
    }
    foreach (['{"attach":"vp:another-order"}','{"other":"vp:Vfixture123"}','{}','[]','{bad-json',
        '{"attach":null}','{"attach":["vp:Vfixture123"]}',123,['attach'=>'vp:Vfixture123']] as $meta) {
        $gateway->order=$order;$gateway->order['biz_meta']=$meta;
        try { $pay->query($intent); throw new \LogicException('Invalid metadata was accepted'); }
        catch (\RuntimeException $e) {
            if ($e->getMessage()!=='渠道订单商品关联信息核对失败') throw $e;
        }
    }
    foreach (['order_id'=>'Vforeign123','env_type'=>2,'order_fee'=>2999,'paid_fee'=>2999] as $field=>$value) {
        $gateway->order=$order;$gateway->order[$field]=$value;
        try { $pay->query($intent); throw new \LogicException('Invalid identity/environment/amount was accepted'); }
        catch (\RuntimeException $e) {}
    }
    echo "PASS query metadata: JSON attach, legacy values, malformed/mismatched metadata, identity, environment and amount\n";
}
