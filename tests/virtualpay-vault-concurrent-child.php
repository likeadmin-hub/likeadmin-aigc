<?php
[$script,$root,$snapshot,$prefix,$tenant]=$argv;
if(!preg_match('/^vpay_vault_fixture_[a-f0-9]{12}_$/D',$prefix)||!in_array((int)$tenant,[891,892],true))throw new RuntimeException('Fixture-only child');
require $root.'/vendor/autoload.php';spl_autoload_register(function($class)use($snapshot){$file=$snapshot.'/'.str_replace('\\','/',$class).'.php';if(str_starts_with($class,'app\\')&&is_file($file))require $file;},true,true);
require $root.'/runtime-loader.php';(new AigcRuntimeApp($root))->initialize();
$config=\think\facade\Db::getConfig();$connection=$config['connections'][$config['default']];if($connection['hostname']!=='127.0.0.1'||$connection['database']!=='z_cn')throw new RuntimeException('Local fixture required');
$config['connections']['vault_fixture']=$connection;$config['connections']['vault_fixture']['prefix']=$prefix;$config['default']='vault_fixture';\think\facade\Config::set($config,'database');
\app\common\service\virtualpay\VirtualPayVault::generate((int)$tenant);echo 'generated';
