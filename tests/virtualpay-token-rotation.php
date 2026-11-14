<?php
/** Runs the real gateway with isolated credentials, cache and transport; no network/DB. */
namespace think\facade {
    final class Cache {
        public static array $rows=[];
        public static function get($key) { return self::$rows[$key]??null; }
        public static function set($key,$value,$ttl): void { self::$rows[$key]=$value; }
    }
}
namespace app\common\service\virtualpay {
    final class VirtualPayConfigService {
        public static array $current=[6=>['app_id'=>'wx-original','app_secret'=>'rotated-secret']];
        public static function manual(int $tenant,string $name): string { return self::$current[$tenant][$name]??''; }
        public static function secret(array $version,string $field): string { return $version[$field]; }
    }
}
namespace {
    require dirname(__DIR__).'/app/common/service/virtualpay/VirtualPayGateway.php';
    use app\common\service\virtualpay\VirtualPayConfigService as Config;
    use app\common\service\virtualpay\VirtualPayGateway as Gateway;
    $gateway=new class extends Gateway {
        public array $calls=[];
        public ?array $failure=null;
        protected function send(string $method,string $path,array $query=[],string $body=''): array {
            $this->calls[]=[$method,$path,$query,$body];
            if($this->failure)return $this->failure;
            return $path==='/cgi-bin/token'?['access_token'=>'token-'.hash('sha256',$query['secret']),'expires_in'=>7200]:['errcode'=>0];
        }
    };
    $checks=0;
    $check=static function(bool $condition,string $message)use(&$checks):void{if(!$condition)throw new \RuntimeException($message);$checks++;};
    $version=['id'=>11,'tenant_id'=>6,'authorizer_id'=>0,'app_id'=>'wx-original','mini_secret'=>'old-secret','app_key'=>'frozen-payment-key','env'=>0];
    $before=$version;
    $one=$gateway->accessToken($version);
    $check($gateway->calls[0][2]['secret']==='rotated-secret','same AppID must use the current AppSecret');
    $check($gateway->calls[0][2]['appid']==='wx-original','token identity remains the original AppID');
    $check($gateway->accessToken($version)===$one&&count($gateway->calls)===1,'same credential reuses cache');
    Config::$current[6]['app_secret']='rotated-again';
    $two=$gateway->accessToken($version);
    $check($two!==$one&&count($gateway->calls)===2,'rotation cannot reuse a token cached for an old secret');
    $gateway->request($version,'/xpay/query_order',['openid'=>'fixture-openid','env'=>0,'order_id'=>'V-fixture']);
    $call=$gateway->calls[2];
    $check($call[2]['pay_sig']===hash_hmac('sha256','/xpay/query_order&'.$call[3],'frozen-payment-key'),'payment HMAC must keep the original frozen AppKey');
    $check(json_decode($call[3],true)['env']===0,'payment environment must remain frozen');
    $check($version===$before,'historical credential snapshot must remain immutable');
    Config::$current[6]=['app_id'=>'wx-other','app_secret'=>'other-app-secret'];
    $gateway->accessToken($version);
    $check(end($gateway->calls)[2]['secret']==='old-secret','another AppID must never donate its credential');
    Config::$current[6]=['app_id'=>'wx-original','app_secret'=>''];
    $check($gateway->accessToken($version)==='token-'.hash('sha256','old-secret'),'blank current settings retain the historical credential');
    Config::$current[7]=['app_id'=>'wx-original','app_secret'=>'tenant-seven-secret'];
    $gateway->accessToken(array_replace($version,['id'=>12,'tenant_id'=>7]));
    $check(end($gateway->calls)[2]['secret']==='tenant-seven-secret','manual credential resolution stays tenant scoped');
    $check(!str_contains(implode('|',array_keys(\think\facade\Cache::$rows)),'secret'),'cache identifiers do not contain plaintext secrets');
    $gateway->failure=['errcode'=>40125,'errmsg'=>'do-not-expose-original-provider-message'];
    try {$gateway->accessToken(array_replace($version,['id'=>999]));throw new \LogicException('Invalid token response accepted');}
    catch(\RuntimeException $error){
        $check(str_contains($error->getMessage(),'40125'),'diagnostics must retain the provider error code');
        $check(!str_contains($error->getMessage(),'do-not-expose')&&!str_contains($error->getMessage(),'old-secret'),'diagnostics must not expose raw credentials or messages');
    }
    $check(count(\think\facade\Cache::$rows)===4,'failed token requests must not be cached');
    echo "PASS {$checks} token rotation, same-app isolation, cache, frozen signing and diagnostic checks\n";
}
