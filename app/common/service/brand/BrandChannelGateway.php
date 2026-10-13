<?php
declare(strict_types=1);
namespace app\common\service\brand;
use Alipay\EasySDK\Kernel\Config;
use Alipay\EasySDK\Kernel\Factory;
use EasyWeChat\Pay\Application;
use RuntimeException;
use think\facade\Db;

/** Merchant credentials are resolved explicitly, never from request/worker tenant context. */
class BrandChannelGateway implements BrandPaymentGateway
{
    public function ways(string $from,array $order,int $terminal): array
    {
        $platform=$from===TenantBrandService::FROM_QUOTA;
        $tenant=(int)$order['tenant_id'];
        $wayTable=$platform?'pay_way':$this->tenantTable('tenant_pay_way',$tenant);
        $configTable=$platform?'pay_config':$this->tenantTable('tenant_pay_config',$tenant);
        $rows=[];
        foreach (array_unique([$terminal,4,3]) as $candidate) {
            $query=Db::name($wayTable)->alias('w')->join($configTable.' c','c.id=w.pay_config_id')
                ->where(['w.scene'=>$candidate,'w.status'=>1])->whereIn('c.pay_way',[2,3]);
            if (!$platform) $query->where('w.tenant_id',$tenant)->where('c.tenant_id',$tenant);
            $rows=$query->field('c.name,c.icon,c.pay_way,w.is_default')->select()->toArray();
            if ($rows) break;
        }
        return $rows;
    }
    public function context(string $from,array $order,int $channel): array
    {
        if (!in_array($channel,[2,3],true)) throw new RuntimeException('请选择微信或支付宝支付');
        $scope=$from===TenantBrandService::FROM_QUOTA?'platform':'tenant';
        BrandPaymentService::table($from);
        $tenant=(int)$order['tenant_id'];
        $config=$this->payConfig($scope,$tenant,$channel);
        $appId=$channel===3?(string)($config['app_id']??''):(string)($config['app_id']??$this->configValue($scope,$tenant,'oa_setting','app_id'));
        $merchant=$channel===2?(string)($config['mch_id']??''):$appId;
        if ($merchant==='' || $appId==='') throw new RuntimeException($scope==='platform'?'平台收款配置尚未完成，请联系平台':'服务商收款配置尚未完成，请联系服务商');
        return ['scope'=>$scope,'tenant_id'=>$tenant,'channel'=>$channel,'merchant_id'=>$merchant,'app_id'=>$appId,'seller_id'=>(string)($config['seller_id']??''),'terminal'=>(int)$order['order_terminal']];
    }

    public function assertOriginal(array $context): array
    {
        $current=$this->context(($context['scope']??'')==='platform'?TenantBrandService::FROM_QUOTA:TenantBrandService::FROM_ORDER,
            ['tenant_id'=>(int)($context['tenant_id']??0),'order_terminal'=>(int)($context['terminal']??5)],(int)($context['channel']??0));
        foreach (['merchant_id','app_id','scope','seller_id'] as $key) if (!hash_equals((string)($context[$key]??''),(string)$current[$key])) throw new RuntimeException('原收款账户已变更，请先由平台核对原交易后处理');
        return $this->payConfig($context['scope'],(int)$context['tenant_id'],(int)$context['channel']);
    }

    public function prepay(array $intent,string $redirect): array
    {
        $context=json_decode($intent['context'],true,512,JSON_THROW_ON_ERROR);
        $ways=$this->ways($intent['order_from'],['tenant_id'=>$intent['tenant_id']],(int)$context['terminal']);
        if (!in_array((int)$intent['channel'],array_map('intval',array_column($ways,'pay_way')),true)) throw new RuntimeException('该支付方式暂未开放，请选择其他支付方式');
        $notify=$this->notifyUrl($intent);
        if ((int)$intent['channel']===2) {
            $response=$this->wechat($context,$notify)->getClient()->postJson('v3/pay/transactions/native',[
                'appid'=>$context['app_id'],'mchid'=>$context['merchant_id'],'description'=>$intent['order_from']===TenantBrandService::FROM_QUOTA?'贴牌额度采购':'贴牌套餐',
                'out_trade_no'=>$intent['pay_sn'],'notify_url'=>$notify,'amount'=>['total'=>(int)$intent['amount_cents'],'currency'=>'CNY'],
                'time_expire'=>date('c',(int)$intent['expires_at']),'attach'=>$intent['order_from'],
            ])->toArray(false);
            if (empty($response['code_url'])) throw new RuntimeException('微信支付暂时不可用，请稍后重试');
            return ['pay_way'=>2,'config'=>$response['code_url']];
        }
        $this->alipay($context,$notify);
        if (!preg_match('~^/(?!/)[^\x00-\x20]*$~D',$redirect)) $redirect='/join-opc/result';
        $result=Factory::payment()->page()->optional('passback_params',$intent['order_from'])->pay('贴牌订单',
            $intent['pay_sn'],BrandOrderPolicy::amount((int)$intent['amount_cents']),request()->domain().$redirect);
        return ['pay_way'=>3,'config'=>$result->body];
    }

    public function query(array $intent): ?array
    {
        $context=json_decode($intent['context'],true,512,JSON_THROW_ON_ERROR);
        if ((int)$intent['channel']===2) {
            $response=$this->wechat($context)->getClient()->get('v3/pay/transactions/out-trade-no/'.rawurlencode($intent['pay_sn']),['query'=>['mchid'=>$context['merchant_id']]])->toArray(false);
            if (($response['trade_state']??'')!=='SUCCESS') return null;
            return $this->wechatReceipt($response,$intent);
        }
        $this->alipay($context);
        $response=Factory::payment()->common()->query($intent['pay_sn']);
        if ((string)($response->code??'')!=='10000' || !in_array($response->tradeStatus??'',['TRADE_SUCCESS','TRADE_FINISHED'],true)) return null;
        return $this->aliReceipt(['out_trade_no'=>$response->outTradeNo??'','trade_no'=>$response->tradeNo??'',
            'total_amount'=>$response->totalAmount??'','trade_status'=>$response->tradeStatus,'app_id'=>$response->appId??$context['app_id'],
            'seller_id'=>$response->sellerId??'','gmt_payment'=>$response->sendPayDate??''],$intent);
    }

    public function wechatReceipt(array $response,array $intent): array
    {
        if (!hash_equals((string)$intent['pay_sn'],(string)($response['out_trade_no']??''))) throw new RuntimeException('微信商户订单号不匹配');
        return ['pay_sn'=>$response['out_trade_no'],'channel'=>2,'merchant_id'=>(string)($response['mchid']??''),'app_id'=>(string)($response['appid']??''),
            'transaction_id'=>(string)($response['transaction_id']??''),'amount_cents'=>(int)($response['amount']['total']??-1),
            'currency'=>(string)($response['amount']['currency']??''),'status'=>($response['trade_state']??'')==='SUCCESS'?'success':'pending',
            'paid_time'=>isset($response['success_time'])?(strtotime($response['success_time'])?:time()):time()];
    }

    public function aliReceipt(array $response,array $intent): array
    {
        $context=json_decode($intent['context'],true,512,JSON_THROW_ON_ERROR);
        if (!hash_equals((string)$intent['pay_sn'],(string)($response['out_trade_no']??''))) throw new RuntimeException('支付宝商户订单号不匹配');
        if (!empty($context['seller_id']) && !hash_equals((string)$context['seller_id'],(string)($response['seller_id']??''))) throw new RuntimeException('支付宝收款账户不匹配');
        return ['pay_sn'=>$response['out_trade_no'],'channel'=>3,'merchant_id'=>(string)($response['app_id']??''),'app_id'=>(string)($response['app_id']??''),
            'transaction_id'=>(string)($response['trade_no']??''),'amount_cents'=>BrandOrderPolicy::cents((string)($response['total_amount']??'')),
            'currency'=>'CNY','status'=>in_array($response['trade_status']??'',['TRADE_SUCCESS','TRADE_FINISHED'],true)?'success':'pending',
            'paid_time'=>!empty($response['gmt_payment'])?(strtotime($response['gmt_payment'])?:time()):time()];
    }

    public function verifyAli(array $data,array $intent): array
    {
        $context=json_decode($intent['context'],true,512,JSON_THROW_ON_ERROR);
        $this->alipay($context);
        if (!Factory::payment()->common()->verifyNotify($data)) throw new RuntimeException('支付通知验签失败');
        return $this->aliReceipt($data,$intent);
    }

    public function wechat(array $context,string $notify=''): Application
    {
        $config=$this->assertOriginal($context);
        $options=['mch_id'=>$context['merchant_id'],'private_key'=>$this->certificate((string)($config['apiclient_key']??'')),
            'certificate'=>$this->certificate((string)($config['apiclient_cert']??'')),'secret_key'=>$config['pay_sign_key']??'',
            'notify_url'=>$notify,'http'=>['throw'=>true,'timeout'=>5.0]];
        return new Application($options);
    }

    private function alipay(array $context,string $notify=''): void
    {
        $config=$this->assertOriginal($context);
        $options=new Config(); $options->protocol='https'; $options->gatewayHost='openapi.alipay.com'; $options->signType='RSA2';
        $options->appId=$context['app_id']; $options->merchantPrivateKey=$config['private_key']??''; $options->notifyUrl=$notify;
        if (($config['mode']??'normal_mode')==='certificate') {
            $options->merchantCertPath=$this->certificate((string)($config['public_cert']??''));
            $options->alipayCertPath=$this->certificate((string)($config['ali_public_cert']??''));
            $options->alipayRootCertPath=$this->certificate((string)($config['ali_root_cert']??''));
        } else $options->alipayPublicKey=$config['ali_public_key']??'';
        Factory::setOptions($options);
    }

    private function payConfig(string $scope,int $tenant,int $channel): array
    {
        $query=Db::name($scope==='platform'?'pay_config':$this->tenantTable('tenant_pay_config',$tenant))->where('pay_way',$channel);
        if ($scope!=='platform') $query->where('tenant_id',$tenant);
        $row=$query->find();
        if (!$row) throw new RuntimeException('收款配置不存在，请联系平台核对');
        return json_decode((string)$row['config'],true)?:[];
    }

    private function configValue(string $scope,int $tenant,string $type,string $key): string
    {
        $query=Db::name($scope==='platform'?'config':$this->tenantTable('tenant_config',$tenant))->where(['type'=>$type,'name'=>$key]);
        if ($scope!=='platform') $query->where('tenant_id',$tenant);
        return (string)$query->value('value','');
    }

    private function tenantTable(string $base,int $tenant): string
    {
        $row=Db::name('tenant')->where('id',$tenant)->whereNull('delete_time')->find();
        if (!$row) throw new RuntimeException('原收款租户不存在，请联系平台核对');
        if ((int)$row['tactics']!==1) return $base;
        if (!preg_match('/^[A-Za-z0-9_]+$/D',(string)$row['sn'])) throw new RuntimeException('租户数据配置异常');
        return $base.'_'.$row['sn'];
    }

    private function certificate(string $pem): string
    {
        if ($pem==='') throw new RuntimeException('支付证书尚未完整配置');
        $dir=app()->getRuntimePath().'oem-cert/';
        if (!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir)) throw new RuntimeException('无法保存支付证书');
        $path=$dir.hash('sha256',$pem).'.pem';
        if (!is_file($path)) { if (file_put_contents($path,$pem,LOCK_EX)===false) throw new RuntimeException('无法保存支付证书'); chmod($path,0600); }
        return $path;
    }

    private function notifyUrl(array $intent): string
    {
        $action=(int)$intent['channel']===2?'notifyWechat':'notifyAli';
        return request()->domain().'/api/brand.payment/'.$action.'?intent_id='.(int)$intent['id'];
    }
}
