<?php
declare(strict_types=1);
// Keep runtime dependencies absent: feature availability must reach the real
// prerequisite check even when an old installation still has ENABLED=false.
function config(string $name, mixed $default=null): mixed { return false; }
require dirname(__DIR__,2).'/app/common/service/decorate/agent/DecorationAgentCatalog.php';
require dirname(__DIR__,2).'/app/common/service/decorate/agent/DecorationAgentService.php';
$result=app\common\service\decorate\agent\DecorationAgentService::bootstrap(1,1);
if (($result['reason']??'') !== '装修Agent运行依赖尚未安装') {
    throw new RuntimeException('Legacy disabled configuration still hides AI decoration prerequisites');
}
echo "PASS: AI decoration is available by default and missing dependencies still surface\n";
