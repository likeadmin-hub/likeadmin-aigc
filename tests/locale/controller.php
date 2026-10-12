<?php
namespace app\common\service {
    final class ConfigService {
        public static array $rows = [];
        public static int $tenant = 1;
        public static function set($type, $key, $value) { self::$rows[self::$tenant][$type][$key] = $value; }
        public static function get($type, $key, $default) { return self::$rows[self::$tenant][$type][$key] ?? $default; }
    }
}
namespace app\api\controller {
    class BaseApiController {
        public $userId = 7, $userInfo = ['tenant_id'=>1], $request;
        public function data($value) { return ['ok'=>true,'data'=>$value]; }
        public function fail($value) { return ['ok'=>false,'error'=>$value]; }
    }
}
namespace {
    require __DIR__.'/../../app/common/service/PcDefaultLocaleService.php';
    require __DIR__.'/../../app/api/controller/LocaleController.php';
    function check($value) { if (!$value) throw new \RuntimeException('Locale controller regression'); }
    $controller = new \app\api\controller\LocaleController();
    $request = new class { public $tenantId=1, $value='en', $post=true; function isPost(){return $this->post;} function post($key){return $this->value;} };
    $controller->request=$request;
    check($controller->detail()['data']['locale']==='zh-CN');
    check($controller->save()['data']['locale']==='en');
    check($controller->detail()['data']['locale']==='en');
    $controller->userId=8;check($controller->detail()['data']['locale']==='zh-CN');
    \app\common\service\PcDefaultLocaleService::save(['pc_default_locale'=>'zh-TW']);
    check($controller->detail()['data']['locale']==='zh-TW');
    check($controller->detail()['data']['configured']===false);
    $controller->userId=7;check($controller->detail()['data']['locale']==='en');
    \app\common\service\PcDefaultLocaleService::save([]);
    check(\app\common\service\PcDefaultLocaleService::read()==='zh-TW');
    try { \app\common\service\PcDefaultLocaleService::validate(['pc_default_locale'=>'xx']); throw new \RuntimeException('Accepted invalid locale'); }
    catch (\InvalidArgumentException $expected) {}
    $request->value='xx';check(!$controller->save()['ok']);
    $request->value='zh-TW';$request->post=false;check(!$controller->save()['ok']);
    $request->post=true;$request->tenantId=2;check(!$controller->save()['ok']);
    $controller->userInfo=['tenant_id'=>2];\app\common\service\ConfigService::$tenant=2;
    check($controller->detail()['data']['locale']==='zh-CN');
    $controller->userId=0;check(!$controller->detail()['ok']);
    echo "Locale controller: default, persistence, validation, method and identity checks passed\n";
}
