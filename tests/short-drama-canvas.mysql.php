<?php
/** Real MySQL concurrency test. Accepts ONLY a separate, non-networked temporary mysqld. */
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/vendor/topthink/framework/src/helper.php';

use app\common\service\app\aigc_short_drama\canvas\CanvasWorkspaceService as Workspaces;
use think\facade\Db;

$worker = ($argv[1] ?? '') === '--worker';
$socket = $worker ? ($argv[2] ?? '') : ($argv[1] ?? '');
if (!preg_match('#^/tmp/short-drama-canvas-test\.[A-Za-z0-9]{6}/mysql\.sock$#D', $socket)) throw new RuntimeException('Refusing non-isolated MySQL socket');
$pdo = new PDO('mysql:unix_socket=' . $socket . ';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$server = $pdo->query('SELECT @@datadir AS dir, @@port AS port')->fetch(PDO::FETCH_ASSOC);
// MySQL 5.7 does not reliably expose --skip-networking through @@skip_networking.
// A generated temp datadir, an allowlisted Unix socket, and port 0 are the
// concrete safety boundary for this deliberately isolated test server.
if (rtrim($server['dir'], '/') !== dirname($socket) || (int)$server['port'] !== 0) throw new RuntimeException('Refusing production/networked MySQL');
$database = $worker ? ($argv[3] ?? '') : 'canvas_isolated_' . bin2hex(random_bytes(8));
if (!preg_match('/^canvas_isolated_[a-f0-9]{16}$/D', $database)) throw new RuntimeException('Invalid test database');
if (!$worker) $pdo->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$container = new \think\Container(); \think\Container::setInstance($container);
$config = new \think\Config(); $container->instance('config', $config); $container->instance('think\Config', $config);
$config->set(['execution_ready' => false, 'execution_provider' => 'unavailable'], 'short_drama_canvas');
$db = new \think\DbManager();
$db->setConfig(['default' => 'isolated', 'connections' => ['isolated' => ['type' => 'mysql', 'socket' => $socket,
    'database' => $database, 'username' => 'root', 'password' => '', 'prefix' => 'la_', 'charset' => 'utf8mb4',
    'fields_strict' => true, 'fields_cache' => false, 'trigger_sql' => false]]]);
$container->instance('think\DbManager', $db);
$service = new Workspaces(91, 7);
$request = ['request_key' => 'concurrent_entry_0001', 'prompt' => 'Concurrent isolated request'];
if ($worker) {
    $result = $service->create($request); $id = (int)$result['workspace']['id'];
    $message = $service->saveIdea($id, ['request_key' => 'concurrent_message_01', 'content' => 'Exactly once']);
    echo json_encode(['workspace' => $id, 'message' => $message['id']]); exit;
}
$sql = file_get_contents(dirname(__DIR__) . '/app/apps/aigc_short_drama/migrations/upgrade_20260914_short_drama_canvas.sql');
preg_match_all('/CREATE TABLE IF NOT EXISTS[^;]+;/', $sql, $statements);
foreach ([1, 2] as $pass) foreach ($statements[0] as $statement) Db::execute($statement);
Db::execute('CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_config` (
    `id` int unsigned NOT NULL AUTO_INCREMENT, `tenant_id` int unsigned NOT NULL DEFAULT 0,
    `config_json` text, `status` tinyint NOT NULL DEFAULT 1,
    `create_time` int unsigned NOT NULL DEFAULT 0, `update_time` int unsigned NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`), UNIQUE KEY `uk_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
Db::name('aigc_short_drama_config')->insert([
    'tenant_id' => 91,
    'config_json' => json_encode(['short_drama_canvas' => ['enabled' => true, 'read_only' => false]], JSON_UNESCAPED_UNICODE),
    'status' => 1,
    'create_time' => time(),
    'update_time' => time(),
]);
$processes = [];
for ($i = 0; $i < 20; $i++) {
    $pipes = [];
    $process = proc_open([PHP_BINARY, __FILE__, '--worker', $socket, $database], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Unable to start test worker');
    fclose($pipes[0]); $processes[] = [$process, $pipes];
}
$results = [];
foreach ($processes as [$process, $pipes]) {
    $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process) !== 0) throw new RuntimeException($error . $output);
    $results[] = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
}
if (count(array_unique(array_column($results, 'workspace'))) !== 1 || count(array_unique(array_column($results, 'message'))) !== 1) throw new RuntimeException('Concurrent replay diverged');
if (Db::name('aigc_short_drama_canvas_workspace')->count() !== 1 || Db::name('aigc_short_drama_canvas_message')->count() !== 2) throw new RuntimeException('Duplicate persistence');
// Four-byte Unicode exercises real MySQL column limits rather than SQLite's permissive TEXT.
$unicode = str_repeat('🎬', 20000);
$emoji = $service->create(['request_key' => 'unicode_entry_0001', 'prompt' => $unicode]);
if ($emoji['messages'][0]['content'] !== $unicode) throw new RuntimeException('Unicode content truncated');
echo json_encode(['passed' => true, 'database' => $database, 'workers' => 20, 'logicalWorkspaces' => 1,
    'initialMessages' => 1, 'followupMessages' => 1, 'migrationReapplied' => true, 'unicodeCharacters' => 20000,
    'scope' => 'Workspace/message concurrency only; no Agent/media/billing executed'], JSON_PRETTY_PRINT) . "\n";
