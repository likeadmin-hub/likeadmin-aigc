<?php
namespace app\common\cache { class TenantAdminAuthCache { public static $allowed=[]; public function __construct($id){} public function getAllUri(){return [];} public function getAdminUri(){return self::$allowed;} } }
namespace app\common\service { class JsonService { public static function fail($message,...$args){return 'DENIED';} } }
namespace think\helper { class Str { public static function camel($value){return $value;} } }
namespace { function request(){return new class {function ip(){return '127.0.0.1';}};}
require dirname(__DIR__).'/app/tenantapi/http/middleware/AuthMiddleware.php';
$request=new class {public $controllerObject;public $adminInfo=['root'=>0,'login_ip'=>'127.0.0.1','admin_id'=>123];public $method='saveDraft';function controller(){return 'distribution.poster';}function action(){return $this->method;}};
$request->controllerObject=new class {function isNotNeedLogin(){return false;}};
$middleware=new \app\tenantapi\http\middleware\AuthMiddleware;
foreach(['lists','detail','saveDraft','publish','copy','sort','setStatus','delete'] as $action){$request->method=$action;if($middleware->handle($request,fn()=> 'ALLOWED')!=='DENIED')throw new \RuntimeException('Unregistered poster action bypassed permission: '.$action);}
\app\common\cache\TenantAdminAuthCache::$allowed=['distribution.poster/delete'];
if($middleware->handle($request,fn()=> 'ALLOWED')!=='ALLOWED')throw new \RuntimeException('Granted permission rejected');
$request->adminInfo['root']=1;
if($middleware->handle($request,fn()=> 'ALLOWED')!=='ALLOWED')throw new \RuntimeException('Root rejected');
echo "PASS: poster permissions fail closed before menu registration; granted and root allowed\n";
}
