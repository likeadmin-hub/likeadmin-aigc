<?php

declare(strict_types=1);

use app\common\service\app\AppRegistryService;
use app\common\service\database\SqlMigrationExecutor;
use think\App;
use think\facade\Config;
use think\facade\Db;

$root = dirname(__DIR__, 4);
require $root . '/vendor/autoload.php';

$app = new App();
$app->initialize();
$config = Config::get('database');
$connection = (array)($config['connections']['mysql'] ?? []);
$prefix = 'itci_' . getmypid() . '_' . random_int(100, 999) . '_';
$failures = [];

try {
    $connection['prefix'] = $prefix;
    $config['connections']['mysql'] = $connection;
    Config::set($config, 'database');

    // Full installs create these platform-core tables before app-center installation begins.
    foreach ([
        'app', 'app_api', 'app_frontend_entry', 'app_install', 'app_migration', 'app_version',
        'tenant', 'tenant_app', 'system_menu', 'tenant_system_menu',
    ] as $table) {
        Db::execute("CREATE TABLE `{$prefix}{$table}` LIKE `la_{$table}`");
    }
    $install = AppRegistryService::installFromLocalWithResult('aigc_canvas');
    foreach (['app', 'app_frontend_entry', 'app_migration', 'aigc_canvas_agent_subtask'] as $table) {
        $exists = Db::query("SHOW TABLES LIKE '{$prefix}{$table}'");
        if (empty($exists)) {
            $failures[] = "install is missing table: {$table}";
        }
    }
    if ((int)Db::name('app')->where('code', 'aigc_canvas')->count() !== 1) {
        $failures[] = 'app registry record was not created';
    }
    if ((int)Db::name('app_frontend_entry')->where('app_code', 'aigc_canvas')->where('terminal', 'pc')->count() !== 1) {
        $failures[] = 'PC frontend entry was not registered';
    }
    if (count((array)($install['migrations'] ?? [])) === 0) {
        $failures[] = 'local app migrations did not execute';
    }

    // The core install owns this durable table. Create its isolated equivalent
    // so the app upgrade scripts can verify their additive columns and indexes.
    Db::execute("CREATE TABLE IF NOT EXISTS `{$prefix}aigc_canvas_agent_tool_call` (
        `id` bigint unsigned NOT NULL AUTO_INCREMENT,
        `tenant_id` int unsigned NOT NULL DEFAULT 0,
        `user_id` int unsigned NOT NULL DEFAULT 0,
        `tool_code` varchar(80) NOT NULL DEFAULT '',
        `provider_task_id` varchar(128) NOT NULL DEFAULT '',
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    foreach ([
        $root . '/upgrade/20260727_aigc_canvas_agent_subtask.sql',
        $root . '/public/upgrade/20260727_aigc_canvas_agent_subtask.sql',
        $root . '/upgrade/20260730_aigc_canvas_agent_tool_delivery.sql',
        $root . '/public/upgrade/20260730_aigc_canvas_agent_tool_delivery.sql',
    ] as $upgradePath) {
        $content = file_get_contents($upgradePath);
        if ($content === false) {
            throw new RuntimeException("Unable to read upgrade script: {$upgradePath}");
        }
        SqlMigrationExecutor::execute($content, (string)($connection['prefix'] ?? 'la_'));
    }
    if (empty(Db::query("SHOW TABLES LIKE '{$prefix}aigc_canvas_agent_subtask'"))) {
        $failures[] = 'upgrade scripts did not preserve the durable subtask table';
    }
    $toolColumns = Db::query("SHOW COLUMNS FROM `{$prefix}aigc_canvas_agent_tool_call`");
    $toolColumnNames = array_map(static fn(array $row): string => (string)$row['Field'], $toolColumns);
    if (!in_array('delivery_item_id', $toolColumnNames, true) || !in_array('attempt_no', $toolColumnNames, true)) {
        $failures[] = 'delivery tool-call columns were not installed';
    }
    $toolIndexes = Db::query("SHOW INDEX FROM `{$prefix}aigc_canvas_agent_tool_call`");
    $toolIndexNames = array_unique(array_map(static fn(array $row): string => (string)$row['Key_name'], $toolIndexes));
    if (!in_array('idx_delivery_item', $toolIndexNames, true) || !in_array('idx_provider_task', $toolIndexNames, true)) {
        $failures[] = 'delivery tool-call indexes were not installed';
    }
} catch (Throwable $e) {
    $failures[] = $e->getMessage();
} finally {
    try {
        $tables = Db::query("SHOW TABLES LIKE '{$prefix}%'");
        foreach ($tables as $row) {
            $table = (string)array_values((array)$row)[0];
            if (!str_starts_with($table, $prefix)) {
                throw new RuntimeException('temporary table cleanup scope check failed');
            }
            Db::execute('DROP TABLE IF EXISTS `' . str_replace('`', '``', $table) . '`');
        }
        if (!empty(Db::query("SHOW TABLES LIKE '{$prefix}%'"))) {
            $failures[] = 'temporary table cleanup did not remove every isolated table';
        }
    } catch (Throwable $e) {
        $failures[] = 'temporary table cleanup failed: ' . $e->getMessage();
    }
}

echo json_encode([
    'passed' => $failures === [],
    'checked' => [
        'isolated_install' => true,
        'app_registry' => true,
        'frontend_entry' => true,
        'upgrade_scripts' => true,
        'delivery_tool_call_migration' => true,
        'cleanup' => true,
    ],
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($failures === [] ? 0 : 1);
