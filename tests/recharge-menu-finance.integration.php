<?php
/** Verify the migration in isolated local fixture tables, not tenant data. */
require dirname(__DIR__) . '/vendor/autoload.php';
(new think\App())->initialize();
set_exception_handler(static function (Throwable $error): void { fwrite(STDERR, $error->getMessage().PHP_EOL); exit(1); });
$db = config('database.connections.mysql');
if ($db['hostname'] !== '127.0.0.1' || $db['database'] !== 'z_cn') throw new RuntimeException('Local fixture database required');
$pdo = new PDO('mysql:host='.$db['hostname'].';port='.$db['hostport'].';dbname='.$db['database'].';charset=utf8mb4', $db['username'], $db['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$prefix = 'recharge_test_' . bin2hex(random_bytes(6)) . '_';
$menu = $prefix . 'tenant_system_menu';
$roles = $prefix . 'tenant_system_role_menu';
try {
    $pdo->exec("CREATE TABLE `$menu` (id INT PRIMARY KEY, tenant_id INT, pid INT, type VARCHAR(2), paths VARCHAR(50), source VARCHAR(20), perms VARCHAR(80), update_time INT) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE `$roles` (role_id INT, menu_id INT, PRIMARY KEY(role_id,menu_id)) ENGINE=InnoDB");
    $pdo->exec("INSERT INTO `$menu` VALUES (1,1,0,'M','finance','core','',0),(2,1,9,'C','recharge','core','recharge.recharge/getConfig',0),(3,1,9,'C','custom','tenant','recharge.recharge/getConfig',0),(4,2,0,'M','finance','core','',0),(5,2,9,'C','recharge','core','recharge.recharge/getConfig',0),(6,3,4,'C','recharge','core','recharge.recharge/getConfig',0)");
    $pdo->exec("INSERT INTO `$roles` VALUES (7,2),(8,5),(9,6)");
    $sql = str_replace('la_', $prefix, file_get_contents(dirname(__DIR__).'/upgrade/20261027_recharge_menu_finance.sql'));
    if (app\common\service\database\RuntimeMigrationPlan::build($sql)['prepare'] !== '') throw new RuntimeException('Unexpected DDL');
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) $pdo->exec($statement);
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) $pdo->exec($statement);
    $parents = $pdo->query("SELECT id,pid FROM `$menu` WHERE id IN (2,3,5) ORDER BY id")->fetchAll(PDO::FETCH_KEY_PAIR);
    $grants = $pdo->query("SELECT COUNT(*) FROM `$roles` WHERE (role_id=7 AND menu_id=1) OR (role_id=8 AND menu_id=4)")->fetchColumn();
    $crossTenant = $pdo->query("SELECT COUNT(*) FROM `$roles` WHERE role_id=9 AND menu_id=4")->fetchColumn();
    if ($parents != [2=>1,3=>9,5=>4] || (int)$grants !== 2 || (int)$crossTenant !== 0) throw new RuntimeException('Migration changed custom menus or misapplied parent grants');
    echo "Recharge menu migration: isolated tenants, custom menu, role grants and repeat run passed\n";
} finally {
    $pdo->exec("DROP TABLE IF EXISTS `$roles`");
    $pdo->exec("DROP TABLE IF EXISTS `$menu`");
}
