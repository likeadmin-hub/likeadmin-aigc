<?php
// Explicit local-only installation. No source credentials are printed.
require dirname(__DIR__,2).'/vendor/autoload.php';
(new think\App())->initialize();
$c=config('database.connections.mysql');
if(PHP_SAPI!=='cli'||!in_array($c['hostname'],['localhost','127.0.0.1'],true)||$c['database']!=='z_cn')throw new RuntimeException('Refusing migration outside verified local z_cn database');
$r=app\common\service\app\AppRegistryService::installFromLocalWithResult('open_platform','1.0.391');
foreach($r['migrations'] as $m)if($m['status']==='failed')throw new RuntimeException($m['error']);
echo json_encode(['module'=>'open_platform','migrations'=>$r['migrations'],'existing_tenants_enabled'=>think\facade\Db::name('open_platform_setting')->where('tenant_id','>',0)->where('enabled',1)->count()],JSON_UNESCAPED_UNICODE),PHP_EOL;
