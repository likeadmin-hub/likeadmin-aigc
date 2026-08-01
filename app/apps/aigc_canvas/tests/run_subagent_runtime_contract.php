<?php

declare(strict_types=1);

$root = dirname(__DIR__, 4);
require $root . '/vendor/autoload.php';

$failures = [];

/** @return string */
function readSource(string $path): string
{
    $source = file_get_contents($path);
    if ($source === false) {
        throw new RuntimeException('Unable to read contract source: ' . $path);
    }
    return $source;
}

/** @return array<int,string> */
function phpFiles(string $directory): array
{
    if (!is_dir($directory)) {
        return [];
    }
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
    foreach ($iterator as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
            $files[] = $file->getPathname();
        }
    }
    return $files;
}

$servicePath = $root . '/app/common/service/app/aigc_canvas/agent/runtime/SubAgentTaskService.php';
$tracePath = $root . '/app/common/service/app/aigc_canvas/agent/runtime/AgentTraceLogger.php';
$runtimePath = $root . '/app/common/service/app/aigc_canvas/AigcCanvasAgentRuntimeService.php';
$dispatcherPath = $root . '/app/common/service/app/aigc_canvas/agent/runtime/SubAgentDispatcher.php';
$installMigrationPath = $root . '/app/apps/aigc_canvas/migrations/install.sql';

$service = readSource($servicePath);
$trace = readSource($tracePath);
$runtime = readSource($runtimePath);
$dispatcher = readSource($dispatcherPath);
$installMigration = readSource($installMigrationPath);

foreach (['parent_run_id', 'child_run_id', 'request_id', 'attempts', 'max_attempts', 'lease_token', 'lease_expire_time'] as $column) {
    if (!preg_match('/`' . preg_quote($column, '/') . '`/', $installMigration)) {
        $failures[] = 'install migration is missing subtask column: ' . $column;
    }
}
foreach (['idx_parent_status', 'idx_claim', 'idx_tenant_request'] as $index) {
    if (!str_contains($installMigration, '`' . $index . '`')) {
        $failures[] = 'install migration is missing subtask index: ' . $index;
    }
}

$appMigrations = glob($root . '/app/apps/aigc_canvas/migrations/*.sql') ?: [];
$upgradeMigrations = array_merge(
    glob($root . '/upgrade/*aigc_canvas*.sql') ?: [],
    glob($root . '/public/upgrade/*aigc_canvas*.sql') ?: []
);
$durableSchemaMigration = false;
foreach (array_merge($appMigrations, $upgradeMigrations) as $migration) {
    if (str_contains(readSource($migration), 'aigc_canvas_agent_subtask')) {
        $durableSchemaMigration = true;
        break;
    }
}
if (!$durableSchemaMigration) {
    $failures[] = 'existing installations have no upgrade migration for aigc_canvas_agent_subtask';
}

if (!str_contains($service, "whereIn('status', ['pending', 'retrying'])")
    || !str_contains($service, "where('status', 'running')->where('lease_expire_time', '<=', \$now)")) {
    $failures[] = 'claim does not include pending/retry and expired-lease recovery states';
}
if (!str_contains($service, "whereRaw('attempts < max_attempts')")
    || !str_contains($service, "whereRaw('attempts >= max_attempts')")
    || !str_contains($service, "'status' => 'failed', 'error' => 'Worker lease expired after maximum attempts'")) {
    $failures[] = 'lease recovery does not enforce maximum attempts before re-claiming exhausted work';
}
if (!str_contains($service, "'status' => 'running'")
    || !str_contains($service, "'attempts' => (int)\$task['attempts'] + 1")
    || !str_contains($service, "'lease_token' => \$token")) {
    $failures[] = 'claim does not atomically establish a running lease and attempt';
}
if (!str_contains($service, "'status' => \$retry ? 'retrying' : 'failed'")
    || !str_contains($service, "'finish_time' => \$retry ? 0 : time()")) {
    $failures[] = 'failed subtask does not transition through retrying before terminal failure';
}
foreach (['succeed', 'fail', 'cancel'] as $transition) {
    $pattern = '/private static function ' . $transition . '\(array \\$task.*?lease_token.*?\$task\[\'lease_token\'\]/s';
    if (!preg_match($pattern, $service)) {
        $failures[] = $transition . ' transition is not protected by the worker lease token';
    }
}
if (!str_contains($service, 'cancelByAssistantMessage')
    || !str_contains($runtime, 'SubAgentTaskService::cancelByAssistantMessage')) {
    $failures[] = 'assistant-message cancellation is not wired from the runtime to subtasks';
}
if (!str_contains($service, "whereIn('status', ['pending', 'retrying'])->update")
    && !str_contains($service, "whereIn('status', ['pending', 'retrying'])->update([")) {
    $failures[] = 'cancellation does not guard against claiming an already-running subtask';
}
if (!str_contains($service, "AgentTraceLogger::cancelRun((int)\$task['child_run_id'], ['canceled' => true])")) {
    $failures[] = 'cancellation does not terminally trace child runs that never reached a worker';
}
if (!str_contains($service, "whereIn('status', ['running', 'pending'])->update([")
    || !str_contains($service, "'status' => 'aggregating'")) {
    $failures[] = 'parent synthesis is not guarded by a single aggregating transition';
}
if (!str_contains($trace, "'tenant_id' => \$tenantId")
    || !str_contains($trace, "'user_id' => \$userId")
    || !str_contains($trace, "'request_id' => \$requestId")
    || !str_contains($trace, "\$where['parent_run_id'] = \$parentRunId")) {
    $failures[] = 'Agent run idempotency is not scoped to tenant, user, request, and parent run';
}

$workerCandidates = array_merge(
    phpFiles($root . '/app/common/command'),
    phpFiles($root . '/app/command')
);
$hasClaimConsumer = false;
$hasExecuteConsumer = false;
foreach ($workerCandidates as $workerPath) {
    $worker = readSource($workerPath);
    $hasClaimConsumer = $hasClaimConsumer || str_contains($worker, 'SubAgentTaskService::claim');
    $hasExecuteConsumer = $hasExecuteConsumer || str_contains($worker, 'SubAgentTaskService::execute');
}
if (!$hasClaimConsumer || !$hasExecuteConsumer) {
    $failures[] = 'no registered command worker consumes SubAgentTaskService::claim/execute';
}
$workerSource = readSource($root . '/app/common/command/CanvasSubAgentWorker.php');
$recoveryPosition = strpos($workerSource, 'SubAgentTaskService::recoverExpiredLeases');
$claimPosition = strpos($workerSource, 'SubAgentTaskService::claim');
if ($recoveryPosition === false || $claimPosition === false || $recoveryPosition > $claimPosition) {
    $failures[] = 'worker does not recover exhausted leases before claiming new subtasks';
}
if (substr_count($service, 'self::isCanceled($context->messageId())') < 2) {
    $failures[] = 'sub-agent execution does not check cancellation before and after the LLM call';
}
if (str_contains($dispatcher, 'array_slice(')
    || !str_contains($dispatcher, 'private const MAX_TASKS = 3')
    || !str_contains($dispatcher, 'count($tasks) > self::MAX_TASKS')) {
    $failures[] = 'sub-agent dispatch must reject over-limit task plans instead of silently dropping work';
}
foreach (['creative_strategy', 'copy_deck', 'visual_brief', 'canvas_plan'] as $workUnit) {
    if (!str_contains($service, "'work_unit' => '" . $workUnit . "'")) {
        $failures[] = 'sub-agent service is missing typed creative work unit: ' . $workUnit;
    }
}
foreach (['input_snapshot', 'output_contract', 'creative_output_contract', 'structuredOutput', "'output' => \$output"] as $contractPart) {
    if (!str_contains($service, $contractPart)) {
        $failures[] = 'sub-agent service is missing structured work-unit contract part: ' . $contractPart;
    }
}
if (!str_contains($dispatcher, 'SubAgentTaskService::normalizeCreativeWorkUnit')) {
    $failures[] = 'dispatcher does not normalize delegated tasks into typed creative work units';
}

$consolePath = $root . '/config/console.php';
if (!is_file($consolePath) || !str_contains(readSource($consolePath), 'CanvasSubAgentWorker')) {
    $failures[] = 'CanvasSubAgentWorker is not registered in config/console.php';
}

echo json_encode([
    'passed' => $failures === [],
    'checked' => [
        'schema' => true,
        'lease_state_machine' => true,
        'cancellation' => true,
        'parent_finalization' => true,
        'run_idempotency' => true,
        'worker_consumer' => true,
        'recovery_guardrails' => true,
        'delegation_limit' => true,
        'structured_creative_work_units' => true,
    ],
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($failures === [] ? 0 : 1);
