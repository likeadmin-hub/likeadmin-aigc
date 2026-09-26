<?php
/** Usage: php scripts/migrate-pc-wechat.php [--apply]. Defaults to a read-only preflight. */
require dirname(__DIR__) . '/vendor/autoload.php';
(new \think\App())->initialize();
$db = \think\facade\Db::connect();
$dry = !in_array('--apply', $argv, true);
echo json_encode(['database' => $db->getConfig('database'), 'host' => $db->getConfig('hostname'), 'dry_run' => $dry], JSON_UNESCAPED_UNICODE) . PHP_EOL;
foreach (\app\common\service\database\PcWechatMigration::run($db->getConfig('prefix'), $db, $dry) as $sql) echo $sql . PHP_EOL;
echo "Migration " . ($dry ? 'preflight' : 'complete') . PHP_EOL;
