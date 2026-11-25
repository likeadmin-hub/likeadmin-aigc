<?php
declare(strict_types=1);

use app\common\service\app\AppCatalogUsage;
use app\common\service\auth\AdminMenuOrder;
use think\facade\Db;

$root = dirname(__DIR__);
require $root . '/runtime-loader.php';
(new AigcRuntimeApp($root))->initialize();

$config = config('database.connections.mysql');
if (PHP_SAPI !== 'cli' || ($config['hostname'] ?? '') !== '127.0.0.1'
    || ($config['database'] ?? '') !== 'z_cn' || Db::query('SELECT DATABASE() AS db')[0]['db'] !== 'z_cn') {
    throw new RuntimeException('Verified local z_cn database required.');
}

$table = $config['prefix'] . 'ai_app_task';
if (!preg_match('/^\w+$/D', $table)) throw new RuntimeException('Invalid local table name.');

// The connection-local table shadows the real table for the service query.
Db::execute("CREATE TEMPORARY TABLE `{$table}` (
    tenant_id INT, user_id INT, app_code VARCHAR(80), parent_task_id INT, create_time INT
)");
try {
    $recent = time() - 3600;
    $past = $recent - 30 * 86400;
    foreach ([
        [17, 3, 'fixture_video', 0, $recent],
        [17, 3, 'fixture_video', 0, $recent],
        [17, 7, 'fixture_video', 0, $recent],
        [18, 3, 'fixture_video', 0, $recent],
        [17, 4, 'fixture_video', 42, $recent],
        [17, 5, 'fixture_video', 0, $past],
        [17, 0, 'fixture_video', 0, $recent],
        [0, 14, 'fixture_video', 0, $recent],
        [17, 3, 'fixture_image', 0, $recent],
    ] as $row) {
        Db::execute("INSERT INTO `{$table}` VALUES (?, ?, ?, ?, ?)", $row);
    }
    $apps = [['code' => 'fixture_video'], ['code' => 'fixture_image'], ['code' => 'fixture_unused']];
    foreach ([[null, [3, 1, 0]], [17, [2, 1, 0]], [18, [1, 0, 0]], [0, [0, 0, 0]]] as [$tenant, $expected]) {
        if (array_column(AppCatalogUsage::withCounts($apps, $tenant), 'usage_user_count') !== $expected) {
            throw new RuntimeException('Unexpected usage count for tenant ' . ($tenant ?? 'all'));
        }
    }
} finally {
    Db::execute("DROP TEMPORARY TABLE `{$table}`");
}

$mall = ['paths' => 'power-mall', 'children' => [
    ['component' => 'power_mall/package'],
    ['component' => 'power_mall/market'],
    ['component' => 'power_mall/records'],
]];
$other = ['paths' => 'other', 'children' => $mall['children']];
$ordered = AdminMenuOrder::marketFirst([$other, $mall]);
if ($ordered[0] !== $other || array_column($ordered[1]['children'], 'component') !== [
    'power_mall/market', 'power_mall/package', 'power_mall/records',
]) {
    throw new RuntimeException('Market must appear first only inside compute mall.');
}
unset($mall['children'][1]);
if (AdminMenuOrder::marketFirst([$mall]) !== [$mall]) {
    throw new RuntimeException('Restricted menus must stay restricted.');
}

echo "Application usage and compute mall menu order verified.\n";
