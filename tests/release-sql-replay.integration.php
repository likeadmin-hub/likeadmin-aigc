<?php
// Replay package SQL on empty clones of local table structures, never business rows.
require dirname(__DIR__) . '/vendor/autoload.php';
(new \think\App())->initialize();
use think\facade\Db;
use app\common\service\database\SqlMigrationExecutor;
$dir = realpath($argv[1] ?? '');
$root = realpath(dirname(__DIR__) . '/runtime/system_update_staging');
if (!$dir || !$root || !str_starts_with($dir, $root . DIRECTORY_SEPARATOR)) throw new RuntimeException('Expected local staging SQL directory');
$files = glob($dir . '/*.sql');
sort($files);
$prefix = 'r' . bin2hex(random_bytes(3)) . '_';
$tables = [];
foreach ($files as $file) {
    preg_match_all('/\bla_([a-zA-Z][a-zA-Z0-9_]*)/', file_get_contents($file), $matches);
    foreach ($matches[1] as $table) $tables[$table] = true;
}
$created = [];
try {
    foreach (array_keys($tables) as $table) {
        if (strlen($prefix . $table) > 64) throw new RuntimeException('Test table name too long');
        $exists = Db::query('SELECT COUNT(*) AS n FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', ['la_' . $table]);
        if ((int)$exists[0]['n']) Db::execute('CREATE TABLE `' . $prefix . $table . '` LIKE `la_' . $table . '`');
        $created[] = $table;
    }
    foreach ([1,2] as $pass) {
        foreach ($files as $file) {
            echo 'Replay ' . $pass . ': ' . basename($file) . "\n";
            // Use the installed updater's duplicate-index/column compatibility behavior.
            SqlMigrationExecutor::execute(file_get_contents($file), $prefix);
        }
    }
    echo 'PASS: all ' . count($files) . " SQL files replayed twice\n";
} finally {
    foreach ($created as $table) Db::execute('DROP TABLE IF EXISTS `' . $prefix . $table . '`');
}
