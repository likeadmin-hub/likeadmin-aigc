<?php
foreach(['OpenPolicy','OpenException','OpenToolRegistry'] as $class)require dirname(__DIR__,2).'/app/common/service/open_platform/'.$class.'.php';
use app\common\service\open_platform\OpenToolRegistry;
$all=OpenToolRegistry::all(); if(count($all)!==27)throw new RuntimeException('tool count');
foreach(['aigc_short_drama','aigc_canvas','aigc_geo'] as $app)if(isset($all[$app]))throw new RuntimeException('excluded tool');
foreach($all as $app=>$tool)foreach($tool['operations'] as $route=>$op){if(isset($op['controller'])){if($op['action']==='setup'||$op['action']==='retry'||preg_match('/Admin|Channel/', $op['controller']))throw new RuntimeException('administrative operation exposed');require_once dirname(__DIR__,2).'/vendor/autoload.php';$class='app\\api\\controller\\app\\'.$app.'\\'.$op['controller'].'Controller';if(!method_exists($class,$op['action']))throw new RuntimeException('invalid binding '.$route);}}
$op=OpenToolRegistry::match('GET','/api/v1/tools/aigc_image/tasks/123');if($op['params']['task_id']!=='123')throw new RuntimeException('route mismatch');
echo 'PASS 27 tools and '.array_sum(array_map(fn($x)=>count($x['operations']),$all))." registered operations\n";
