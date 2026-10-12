<?php
// Standalone loopback preview. It never changes the installed generation or exposes a production route.
if(PHP_SAPI!=='cli-server'||!in_array($_SERVER['REMOTE_ADDR']??'',['127.0.0.1','::1'],true)){http_response_code(404);exit;}
$siteRoot=dirname(__DIR__,2);
$_SERVER['SCRIPT_NAME']='/index.php';$_SERVER['PHP_SELF']='/index.php';$_SERVER['SCRIPT_FILENAME']=$siteRoot.'/public/index.php';
require $siteRoot.'/vendor/autoload.php';
$guard=new \think\App($siteRoot);$guard->initialize();$c=$guard->config->get('database.connections.mysql');
if(($c['hostname']??'')!=='127.0.0.1'||($c['database']??'')!=='z_cn'){http_response_code(503);exit('Local preview unavailable');}
$GLOBALS['AIGC_RUNTIME_GENERATION_OVERRIDE']=$siteRoot;
require $siteRoot.'/public/index.php';
