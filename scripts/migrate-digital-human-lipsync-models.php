<?php

/** Local development only. Preview by default; explicitly pin the DB for --apply. */
require dirname(__DIR__) . '/vendor/autoload.php';
(new \think\App())->initialize();
$db = \think\facade\Db::connect();
$options = getopt('', ['apply', 'database:']);
$database = (string)$db->getConfig('database');
$host = (string)$db->getConfig('hostname');
if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
    throw new \RuntimeException('This migration only supports a local development database');
}
if (isset($options['apply']) && (string)($options['database'] ?? '') !== $database) {
    throw new \RuntimeException('Use --database=<local database name> to pin the migration target');
}
$prefix = (string)$db->getConfig('prefix');
if (!preg_match('/^[a-zA-Z0-9_]+$/D', $prefix)) {
    throw new \RuntimeException('Invalid database prefix');
}
$sql = str_replace('`la_aigc_digital_human_channel`', '`' . $prefix . 'aigc_digital_human_channel`',
    file_get_contents(dirname(__DIR__) . '/upgrade/20261007_digital_human_lipsync_models.sql'));
$before = $db->name('aigc_digital_human_channel')->field('tenant_id,code,provider,model')->select()->toArray();
$affected = isset($options['apply']) ? $db->execute($sql) : 0;
$after = $db->name('aigc_digital_human_channel')->field('tenant_id,code,provider,model')->select()->toArray();
echo json_encode(['database' => $database, 'applied' => isset($options['apply']), 'affected' => $affected,
    'before' => $before, 'after' => $after], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
