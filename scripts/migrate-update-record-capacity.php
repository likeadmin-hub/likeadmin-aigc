<?php
/** Explicit old-updater recovery: inspect by default, back up affected rows before --apply. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') throw new RuntimeException('CLI only');
$options = getopt('', ['root:', 'apply']);
$root = realpath($options['root'] ?? dirname(__DIR__));
if (!$root || !is_file($root.'/vendor/autoload.php')) throw new RuntimeException('Invalid site root');
chdir($root);
require $root.'/vendor/autoload.php';
(new \think\App($root.DIRECTORY_SEPARATOR))->initialize();
set_exception_handler(static function (\Throwable $error): void {
    fwrite(STDERR, $error->getMessage().PHP_EOL);
    exit(1);
});
// Explicit path lets operators copy this script + helper to an old canonical site.
require_once dirname(__DIR__).'/app/common/service/database/RuntimeUpdateRecordCapacity.php';
use app\common\service\database\RuntimeUpdateRecordCapacity as Capacity;
use think\facade\Db;
$db = Db::connect();
$prefix = (string)$db->getConfig('prefix');
echo 'Target: '.$db->getConfig('hostname').'/'.$db->getConfig('database').' prefix='.$prefix.PHP_EOL;
echo json_encode(Capacity::all($prefix, $db, false), JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
if (!isset($options['apply'])) { echo "Inspection only; use --apply after site/database backup.\n"; exit; }
$locks = [];
try {
    // Match canonical updates: exclude writers, retain existing worker readers.
    foreach (['site-update-intent.lock'=>LOCK_EX, 'site-update.lock'=>LOCK_SH] as $name=>$mode) {
        $path = $root.'/runtime/'.$name;
        if (is_link($path)) throw new RuntimeException('Invalid lock path');
        $h = fopen($path, 'c+');
        if (!$h || !flock($h, $mode|LOCK_NB)) throw new RuntimeException('Site update busy; retry after it completes');
        $locks[] = $h;
    }
    $deployment = json_decode((string)@file_get_contents($root.'/runtime/direct-deployment.json'), true);
    $canonical = ($deployment['schema'] ?? null) === 1 && ($deployment['mode'] ?? '') === 'canonical';
    if (is_file($root.'/runtime/direct-update-active.json') || (is_file($root.'/.composer-runtime/current.json') && !$canonical)
        || Db::name('update_task')->where('status', 'running')->count()) throw new RuntimeException('Pending/legacy update; inspect before repair');
    $backup = $root.'/runtime/update_record_capacity_backup/'.date('YmdHis').'-'.bin2hex(random_bytes(4));
    if (!mkdir($backup, 0700, true)) throw new RuntimeException('Cannot create backup');
    $before = [];
    foreach (['update_task', 'update_package'] as $table) {
        $before[$table] = Db::name($table)->order('id')->select()->toArray();
        $bytes = json_encode(['schema'=>$db->query('SHOW CREATE TABLE `'.$prefix.$table.'`'), 'rows'=>$before[$table]], JSON_THROW_ON_ERROR);
        if (file_put_contents($backup.'/'.$table.'.json', $bytes) !== strlen($bytes)) throw new RuntimeException('Backup incomplete');
    }
    $result = Capacity::all($prefix, $db);
    foreach ($before as $table => $rows) {
        if (Db::name($table)->order('id')->select()->toArray() !== $rows) throw new RuntimeException('Update records changed; inspect backup before continuing');
    }
    echo json_encode(['result'=>$result, 'records_preserved'=>true, 'backup'=>$backup], JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    foreach (array_reverse($locks) as $h) { flock($h, LOCK_UN); fclose($h); }
}
