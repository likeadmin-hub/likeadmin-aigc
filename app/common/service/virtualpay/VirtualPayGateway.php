<?php
declare(strict_types=1);
namespace app\common\service\virtualpay;
use app\common\service\wechat\OpenPlatformService;
use think\facade\Cache;
/** External transport is a constructor dependency, never an HTTP request or environment bypass. */
class VirtualPayGateway
{
    private ?\Closure $transport;
    public function __construct(?\Closure $transport=null) { $this->transport=$transport; }
    protected function send(string $method,string $path,array $query=[],string $body=''): array
    {
        if($this->transport)return ($this->transport)($method,$path,$query,$body);
        try { $response=(new \GuzzleHttp\Client(['base_uri'=>'https://api.weixin.qq.com','timeout'=>10,'connect_timeout'=>4,'http_errors'=>false]))->request($method,$path,['query'=>$query,'headers'=>['Content-Type'=>'application/json'],'body'=>$body]); } catch (\Throwable $e) { throw new \RuntimeException('微信服务暂时无法连接，请稍后核对支付状态'); }
        if($response->getStatusCode()!==200)throw new \RuntimeException('微信服务暂时无法连接，请稍后核对支付状态');
        $data=json_decode((string)$response->getBody(),true,512,JSON_THROW_ON_ERROR);
        if(!is_array($data))throw new \RuntimeException('微信返回的数据格式不正确');
        return $data;
    }
    public function exchange(int $tenant,string $code): array
    {
        $i=VirtualPayConfigService::identity($tenant);
        if($i['authorizer_id']&&!$this->transport)return OpenPlatformService::authorizedMnpSessionByCode($tenant,$code);
        return $this->send('GET','/sns/jscode2session',['appid'=>$i['app_id'],'secret'=>VirtualPayConfigService::manual($tenant,'app_secret'),'js_code'=>$code,'grant_type'=>'authorization_code']);
    }
    public function accessToken(array $v): string
    {
        if($this->transport)return 'test-transport-token';
        if($v['authorizer_id']) {
            $identity=\think\facade\Db::name('wechat_authorizers')->where(['id'=>(int)$v['authorizer_id'],'tenant_id'=>(int)$v['tenant_id'],'authorizer_appid'=>$v['app_id'],'authorization_status'=>1])->find();
            if (!$identity) throw new \RuntimeException('原支付小程序授权已失效，请联系管理员核对');
            return OpenPlatformService::authorizerToken((int)$v['authorizer_id']);
        }
        $cache='vpay.token.'.(int)$v['id'];$old=Cache::get($cache);if(is_string($old)&&$old!=='')return $old;
        $data=$this->send('GET','/cgi-bin/token',['grant_type'=>'client_credential','appid'=>$v['app_id'],'secret'=>VirtualPayConfigService::secret($v,'mini_secret')]);
        if(empty($data['access_token']))throw new \RuntimeException('小程序凭据不可用，请联系管理员核对原支付配置');
        Cache::set($cache,$data['access_token'],max(60,(int)($data['expires_in']??7200)-300));return $data['access_token'];
    }
    public function request(array $v,string $path,array $payload,bool $signed=true): array
    {
        if(!in_array($path,['/xpay/query_order','/xpay/refund_order','/xpay/notify_provide_goods','/wxa/sec/order/is_trade_managed','/wxa/sec/order/get_order','/wxa/sec/order/upload_shipping_info'],true))throw new \InvalidArgumentException('不支持的支付接口');
        $raw=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$query=['access_token'=>$this->accessToken($v)];
        if($signed)$query['pay_sig']=hash_hmac('sha256',$path.'&'.$raw,VirtualPayConfigService::secret($v,'app_key'));
        $data=$this->send('POST',$path,$query,$raw);
        if(isset($data['errcode'])&&(int)$data['errcode']!==0)throw new \RuntimeException('微信支付核对未完成（错误码'.(int)$data['errcode'].'），请稍后重试或联系管理员');
        return $data;
    }
}
