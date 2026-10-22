<?php
namespace app\common\cache {
    final class TenantAdminAuthCache {
        public static array $uris=[];
        public function __construct($id){}
        public function getAllUri(){return ['finance.virtual_pay/save','finance.virtual_pay/detail'];}
        public function getAdminUri(){return self::$uris;}
    }
}
namespace app\common\service { final class JsonService { public static function fail($message,...$arguments){return ['error'=>$message];} } }
namespace {
    require (getenv('AIGC_SERVER_ROOT')?:dirname(__DIR__)).'/vendor/topthink/think-helper/src/helper/Str.php';
    require dirname(__DIR__).'/app/tenantapi/http/middleware/AuthMiddleware.php';
    class FixtureRequest {
        public array $adminInfo=['login_ip'=>'127.0.0.1','root'=>0,'admin_id'=>1];public $controllerObject;
        public function __construct(){$this->controllerObject=new class{public function isNotNeedLogin(){return false;}};}
        public function controller(){return 'finance.VirtualPay';}public function action(){return 'generateVault';}public function ip(){return '127.0.0.1';}
    }
    $request=new FixtureRequest();function request(){global $request;return $request;}
    $middleware=new \app\tenantapi\http\middleware\AuthMiddleware();$next=fn()=>'allowed';$checks=0;
    function check($condition,$message){global $checks;if(!$condition)throw new \RuntimeException($message);$checks++;}
    \app\common\cache\TenantAdminAuthCache::$uris=['finance.virtual_pay/detail'];check(is_array($middleware->handle($request,$next)),'read permission cannot generate');
    \app\common\cache\TenantAdminAuthCache::$uris=['finance.virtual_pay/save'];check($middleware->handle($request,$next)==='allowed','save permission permits generation');
    \app\common\cache\TenantAdminAuthCache::$uris=[];check(is_array($middleware->handle($request,$next)),'no save permission cannot generate');
    $request->adminInfo['root']=1;check($middleware->handle($request,$next)==='allowed','root still allowed');
    echo "PASS vault generation permission alias: $checks checks\n";
}
