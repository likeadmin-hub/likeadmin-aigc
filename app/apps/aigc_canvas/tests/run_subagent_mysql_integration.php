<?php

declare(strict_types=1);

use app\common\service\app\aigc_canvas\agent\runtime\SubAgentTaskService;
use think\App;
use think\facade\Db;

$root = dirname(__DIR__, 4);
const TEST_PREFIX = 'it_canvas_subagent_';
const TEST_TENANT_ID = 990001;
const TEST_USER_ID = 990001;

/** @return never */
function fail(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fail($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function boot(string $root): void
{
    require_once $root . '/vendor/autoload.php';
    $app = new App($root);
    \think\Container::setInstance($app);
    $app->initialize();

    // The app Db service holds the Config object, not a raw array. Update the
    // nested connection config, then replace the default connection before any query.
    $database = $app->config->get('database');
    $database['connections']['mysql']['prefix'] = TEST_PREFIX;
    $app->config->set($database, 'database');
    $app->db->connect('mysql', true);
}

function testTable(): string
{
    return TEST_PREFIX . 'aigc_canvas_agent_subtask';
}

function createTestTable(): void
{
    $table = testTable();
    Db::execute("CREATE TABLE IF NOT EXISTS `{$table}` (
        `id` bigint unsigned NOT NULL AUTO_INCREMENT,
        `tenant_id` int unsigned NOT NULL DEFAULT 0,
        `user_id` int unsigned NOT NULL DEFAULT 0,
        `project_id` int unsigned NOT NULL DEFAULT 0,
        `thread_id` int unsigned NOT NULL DEFAULT 0,
        `parent_run_id` bigint unsigned NOT NULL DEFAULT 0,
        `child_run_id` bigint unsigned NOT NULL DEFAULT 0,
        `request_id` varchar(96) NOT NULL DEFAULT '',
        `agent_code` varchar(64) NOT NULL DEFAULT '',
        `sequence` int unsigned NOT NULL DEFAULT 0,
        `status` varchar(30) NOT NULL DEFAULT 'pending',
        `payload_json` longtext,
        `result_json` longtext,
        `error` text,
        `attempts` int unsigned NOT NULL DEFAULT 0,
        `max_attempts` int unsigned NOT NULL DEFAULT 2,
        `lease_token` varchar(128) NOT NULL DEFAULT '',
        `lease_expire_time` int unsigned NOT NULL DEFAULT 0,
        `create_time` int unsigned NOT NULL DEFAULT 0,
        `update_time` int unsigned NOT NULL DEFAULT 0,
        `finish_time` int unsigned NOT NULL DEFAULT 0,
        `delete_time` int unsigned NOT NULL DEFAULT 0,
        PRIMARY KEY (`id`),
        KEY `idx_parent_status` (`parent_run_id`,`status`,`delete_time`),
        KEY `idx_claim` (`status`,`lease_expire_time`,`sequence`,`id`),
        KEY `idx_tenant_request` (`tenant_id`,`user_id`,`request_id`,`delete_time`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function seedTask(int $sequence, string $status = 'pending', int $attempts = 0, int $messageId = 0): int
{
    $now = time();
    return (int)Db::name('aigc_canvas_agent_subtask')->insertGetId([
        'tenant_id' => TEST_TENANT_ID,
        'user_id' => TEST_USER_ID,
        'project_id' => 1,
        'thread_id' => 1,
        'parent_run_id' => 0,
        'child_run_id' => 0,
        'request_id' => 'mysql-integration-' . $sequence,
        'agent_code' => 'planner',
        'sequence' => $sequence,
        'status' => $status,
        'payload_json' => json_encode(['assistant_message_id' => $messageId], JSON_UNESCAPED_SLASHES),
        'result_json' => '{}',
        'error' => '',
        'attempts' => $attempts,
        'max_attempts' => 2,
        'lease_token' => '',
        'lease_expire_time' => 0,
        'create_time' => $now,
        'update_time' => $now,
        'finish_time' => 0,
        'delete_time' => 0,
    ]);
}

function taskById(int $id): array
{
    return (array)Db::name('aigc_canvas_agent_subtask')->where('id', $id)->find();
}

function runClaimChild(string $root): array
{
    if (!function_exists('exec')) {
        fail('PHP exec is required for concurrent claim verification');
    }
    $token = bin2hex(random_bytes(8));
    $output = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'canvas-subagent-claim-' . $token . '.out';
    $error = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'canvas-subagent-claim-' . $token . '.err';
    $command = 'start "" /B ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --claim-child'
        . ' > ' . escapeshellarg($output) . ' 2> ' . escapeshellarg($error);
    exec($command);
    return [$output, $error];
}

function readClaimChild(string $output, string $error): array
{
    $deadline = microtime(true) + 10;
    do {
        $content = is_file($output) ? trim((string)file_get_contents($output)) : '';
        if ($content !== '') {
            $childError = is_file($error) ? trim((string)file_get_contents($error)) : '';
            if ($childError !== '') {
                fail('claim child emitted errors: ' . $childError);
            }
            $claimed = json_decode($content, true);
            if (!is_array($claimed)) {
                fail('claim child did not return JSON: ' . $content);
            }
            return $claimed;
        }
        usleep(50_000);
    } while (microtime(true) < $deadline);

    $childError = is_file($error) ? trim((string)file_get_contents($error)) : '';
    fail('claim child timed out' . ($childError !== '' ? ': ' . $childError : ''));
}

if (($argv[1] ?? '') === '--claim-child') {
    boot($root);
    $claimed = SubAgentTaskService::claim('mysql-integration-' . getmypid(), 180, 1);
    echo json_encode(array_map(static fn(array $task): int => (int)$task['id'], $claimed), JSON_UNESCAPED_SLASHES);
    exit(0);
}

boot($root);
$table = testTable();
$childFiles = [];
$cleanup = static function () use ($table): void {
    Db::execute("DROP TABLE IF EXISTS `{$table}`");
};

try {
    createTestTable();
    Db::execute("TRUNCATE TABLE `{$table}`");

    $firstId = seedTask(1);
    $secondId = seedTask(2);
    [$firstOutput, $firstError] = runClaimChild($root);
    [$secondOutput, $secondError] = runClaimChild($root);
    $childFiles = [$firstOutput, $firstError, $secondOutput, $secondError];
    $claimedIds = array_merge(readClaimChild($firstOutput, $firstError), readClaimChild($secondOutput, $secondError));
    sort($claimedIds);
    assertSameValue([$firstId, $secondId], $claimedIds, 'concurrent workers must claim distinct rows');
    foreach ($claimedIds as $id) {
        $task = taskById((int)$id);
        assertSameValue('running', (string)$task['status'], 'claimed task status');
        assertSameValue(1, (int)$task['attempts'], 'claimed task attempt count');
    }

    $retryTask = taskById($firstId);
    $failTransition = new ReflectionMethod(SubAgentTaskService::class, 'fail');
    $failTransition->setAccessible(true);
    $failTransition->invoke(null, $retryTask, 'temporary failure');
    assertSameValue('retrying', (string)taskById($firstId)['status'], 'first failure must become retrying');

    $reclaimed = SubAgentTaskService::claim('mysql-integration-retry', 180, 1);
    assertSameValue($firstId, (int)($reclaimed[0]['id'] ?? 0), 'retrying task must be claimed again');
    assertSameValue(2, (int)($reclaimed[0]['attempts'] ?? 0), 'retry claim increments attempts');

    Db::name('aigc_canvas_agent_subtask')->where('id', $firstId)->update([
        'status' => 'running',
        'attempts' => 2,
        'lease_token' => 'expired-integration-lease',
        'lease_expire_time' => time() - 1,
    ]);
    assertSameValue(1, SubAgentTaskService::recoverExpiredLeases(), 'expired final attempt recovery count');
    $expired = taskById($firstId);
    assertSameValue('failed', (string)$expired['status'], 'expired final attempt must fail');
    assertSameValue(0, SubAgentTaskService::recoverExpiredLeases(), 'recovery must be idempotent');

    $pendingCancelId = seedTask(3, 'pending', 0, 71001);
    $retryingCancelId = seedTask(4, 'retrying', 1, 71001);
    SubAgentTaskService::cancelByAssistantMessage(TEST_TENANT_ID, TEST_USER_ID, 71001);
    assertSameValue('canceled', (string)taskById($pendingCancelId)['status'], 'pending task cancellation');
    assertSameValue('canceled', (string)taskById($retryingCancelId)['status'], 'retrying task cancellation');

    echo json_encode([
        'passed' => true,
        'checked' => [
            'concurrent_claim' => true,
            'retry_transition' => true,
            'expired_lease_terminal_failure' => true,
            'recovery_idempotency' => true,
            'pending_retrying_cancellation' => true,
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} finally {
    $cleanup();
    foreach ($childFiles as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
}
