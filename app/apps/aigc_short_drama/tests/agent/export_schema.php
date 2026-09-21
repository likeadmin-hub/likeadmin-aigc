<?php
declare(strict_types=1);
// Read-only source schema export: no row, credential, trigger or view copying.
require dirname(__DIR__, 5) . '/vendor/autoload.php';
(new think\App())->initialize();
$config = config('database.connections.mysql');
echo "SET FOREIGN_KEY_CHECKS=0;\n";
foreach (think\facade\Db::query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_TYPE=?', [$config['database'], 'BASE TABLE']) as $row) {
    $table = $row['TABLE_NAME'];
    if (!preg_match('/^la_[a-z0-9_]+$/D', $table)) continue;
    $create = think\facade\Db::query('SHOW CREATE TABLE `' . $table . '`')[0]['Create Table'];
    // Do not leak production row counts through the next auto-increment value.
    $create = preg_replace('/ AUTO_INCREMENT=\d+/', '', $create);
    echo str_replace('CREATE TABLE `', 'CREATE TABLE IF NOT EXISTS `', $create), ";\n";
}
echo "SET FOREIGN_KEY_CHECKS=1;\n";
