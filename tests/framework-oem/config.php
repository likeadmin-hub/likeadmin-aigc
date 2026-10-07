<?php
namespace app\common\enum {class AdminTerminalEnum {static function isPlatform(){return true;}}}
namespace app\common\model {class Config {static $value=null;function where($q){return $this;}function value($key){return self::$value;}}class TenantConfig extends Config{}}
namespace {function config($key){return 'generic';}
$root=sys_get_temp_dir().'/oem-config-'.bin2hex(random_bytes(5));mkdir($root.'/app/common/service',0755,true);mkdir($root.'/oem');
foreach(['OemBrandService','ConfigService'] as $name){copy(dirname(__DIR__,2).'/app/common/service/'.$name.'.php',$root.'/app/common/service/'.$name.'.php');require $root.'/app/common/service/'.$name.'.php';}
file_put_contents($root.'/oem/brand.json',json_encode(['schema_version'=>1,'name'=>'Customer AI','slug'=>'customer-ai','logo'=>'oem-assets/v1/logo.png']));
try {
\app\common\model\Config::$value='[]';if(\app\common\service\ConfigService::get('copyright','config',[])!==[])throw new \RuntimeException('OEM empty copyright was replaced with original company');
\app\common\model\Config::$value=null;if(\app\common\service\ConfigService::get('platform','name','old default')!=='Customer AI')throw new \RuntimeException('OEM default lost to generic caller default');
\app\common\model\Config::$value='Client custom';if(\app\common\service\ConfigService::get('platform','name')!=='Client custom')throw new \RuntimeException('Saved settings lost');
echo "PASS 3 config precedence assertions\n";
}finally{unlink($root.'/oem/brand.json');foreach(['OemBrandService','ConfigService'] as $name)unlink($root.'/app/common/service/'.$name.'.php');rmdir($root.'/oem');rmdir($root.'/app/common/service');rmdir($root.'/app/common');rmdir($root.'/app');rmdir($root);}}
