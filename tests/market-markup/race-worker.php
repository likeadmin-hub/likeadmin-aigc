<?php
require getcwd().'/vendor/autoload.php';
$root=$argv[2];spl_autoload_register(function($c)use($root){$p=$root.'/'.str_replace('\\','/',$c).'.php';if(is_file($p))require $p;},true,true);
(new think\App())->initialize();
$c=config('database');$c['connections']['markup_race']=$c['connections']['mysql'];$c['connections']['markup_race']['prefix']=$argv[1];$c['default']='markup_race';think\facade\Config::set($c,'database');
echo "READY\n";fflush(STDOUT);fgets(STDIN);
try {app\common\service\power\MarketPriceRuleService::apply(77,22,$argv[3]);echo "applied\n";}
catch(RuntimeException $e){echo 'rejected:'.$e->getMessage()."\n";}
