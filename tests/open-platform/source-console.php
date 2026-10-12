<?php
// Process-only source preview; never changes the installed runtime pointer.
$siteRoot=dirname(__DIR__,2);
require $siteRoot.'/vendor/autoload.php';
(new think\App($siteRoot))->initialize();
$c=config('database.connections.mysql');
if(PHP_SAPI!=='cli'||$c['hostname']!=='127.0.0.1'||$c['database']!=='z_cn')throw new RuntimeException('Verified local source preview only');
$GLOBALS['AIGC_RUNTIME_GENERATION_OVERRIDE']=$siteRoot;
require $siteRoot.'/runtime-loader.php';
(new AigcRuntimeApp($siteRoot))->console->run();
