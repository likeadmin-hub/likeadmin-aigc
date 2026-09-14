<?php
// Uses uniquely prefixed disposable tables, never touches business tables.
require dirname(__DIR__) . '/vendor/autoload.php';
(new \think\App())->initialize();
use think\facade\Db;
use app\common\service\database\SqlMigrationExecutor;
use app\common\model\tenant\TenantPackage;
use app\common\model\power\TenantPowerPackage;

$prefix = 'r306_' . bin2hex(random_bytes(5)) . '_';
$tables = ['tenant_package', 'tenant_power_package', 'tenant_system_menu', 'system_menu'];
$run = static function (string $sql) use ($prefix): void { SqlMigrationExecutor::execute($sql, $prefix); };
try {
    foreach (['tenant_package', 'tenant_power_package'] as $table) {
        $run('CREATE TABLE `la_' . $table . '` (`id` int unsigned PRIMARY KEY AUTO_INCREMENT, `name` varchar(80), `delete_time` int unsigned NOT NULL DEFAULT 0, `create_time` int unsigned DEFAULT 0, `update_time` int unsigned DEFAULT 0)');
        $run('INSERT INTO `la_' . $table . '` (`name`,`delete_time`) VALUES (\'legacy-live\',0),(\'deleted\',12345)');
    }
    $run('CREATE TABLE `la_tenant_system_menu` (`id` int PRIMARY KEY)');
    $run('CREATE TABLE `la_system_menu` (`name` varchar(80), `component` varchar(120), `source_menu_key` varchar(120), `source` varchar(20), `update_time` int)');
    $run("INSERT INTO `la_system_menu` VALUES ('算力消耗明细','power_mall/consumption','core_tenant_power_consume_platform','core',0)");
    foreach ([1,2] as $pass) {
        foreach (['000_20260914_release_305_schema_repair.sql', '20260915_release_306_package_menu_repair.sql'] as $file) {
            $run(file_get_contents(dirname(__DIR__) . '/upgrade/' . $file));
        }
        foreach ([$prefix . 'tenant_package', $prefix . 'tenant_power_package'] as $table) {
            $rows = Db::query('SELECT * FROM `' . $table . '` ORDER BY id');
            if ($rows[0]['delete_time'] !== null || (int)$rows[1]['delete_time'] !== 12345) throw new RuntimeException('Soft delete migration failed');
        }
        echo "Migration pass $pass OK\n";
    }
    foreach (['tenant_package'=>TenantPackage::class, 'tenant_power_package'=>TenantPowerPackage::class] as $table => $model) {
        $table = $prefix . $table;
        if ($model::table($table)->count() !== 1) throw new RuntimeException('Legacy active package must be visible');
        $row = new $model();
        $tableProperty = new ReflectionProperty(\think\Model::class, 'table');
        $tableProperty->setAccessible(true);
        $tableProperty->setValue($row, $table);
        $row->save(['name'=>'new-package']);
        if ($model::table($table)->count() !== 2) throw new RuntimeException('New package must be visible');
        $row->delete();
        if ($model::table($table)->count() !== 1) throw new RuntimeException('Deleted package must be hidden');
    }
    $menu = Db::query('SELECT * FROM `' . $prefix . 'system_menu`')[0];
    if ($menu['name'] !== '算力明细' || $menu['component'] !== 'tenant/power_consume/index') throw new RuntimeException('Menu mismatch');
    echo "Package create/list/delete and menu assertions OK\n";
} finally {
    foreach ($tables as $table) Db::execute('DROP TABLE IF EXISTS `' . $prefix . $table . '`');
}
