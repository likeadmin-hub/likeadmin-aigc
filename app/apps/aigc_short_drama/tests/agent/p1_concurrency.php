<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

use think\facade\Db;
use app\common\service\app\aigc_short_drama\ShortDramaCanvasService as Canvas;
use app\common\service\app\aigc_short_drama\canvas_agent\GraphService as Graph;

if (($argv[1] ?? '') === 'worker') {
    echo "READY\n";
    if (trim((string)fgets(STDIN)) !== 'GO') throw new RuntimeException('Missing barrier release');
    try {
        $result = Graph::patch(91001, 92001, (int)$argv[2], [
            'request_key' => $argv[3], 'expected_revision' => 0,
            'operations' => [['op' => 'add_node', 'node' => ['id' => 1, 'type' => 'text', 'metadata' => ['content' => 'Concurrent fixture']]]],
        ]);
        echo json_encode(['ok' => $result], JSON_THROW_ON_ERROR), PHP_EOL;
    } catch (RuntimeException $e) {
        if ($e->getMessage() !== 'VERSION_CONFLICT') throw $e;
        echo json_encode(['error' => $e->getMessage()]), PHP_EOL;
    }
    exit(0);
}

/** Separate PHP processes, separate DB connections, released after all ready. */
function raceGraph(int $id, array $keys): array
{
    $children = [];
    try {
        foreach ($keys as $key) {
            $process = proc_open([PHP_BINARY, __FILE__, 'worker', (string)$id, $key], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($process)) throw new RuntimeException('Cannot start graph worker');
            stream_set_timeout($pipes[1], 20);
            $children[] = [$process, $pipes];
        }
        foreach ($children as [, $pipes]) {
            if (trim((string)fgets($pipes[1])) !== 'READY') throw new RuntimeException('Worker failed readiness barrier');
        }
        foreach ($children as [, $pipes]) fwrite($pipes[0], "GO\n");
        $results = [];
        foreach ($children as [, $pipes]) {
            $line = fgets($pipes[1]);
            if (!$line) throw new RuntimeException('Worker failed result barrier');
            $results[] = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        }
        return $results;
    } finally {
        foreach ($children as [$process, $pipes]) {
            foreach ($pipes as $pipe) fclose($pipe);
            if (proc_get_status($process)['running']) proc_terminate($process);
            proc_close($process);
        }
    }
}

$ownedIds = [];
try {
    $id = Canvas::create(91001, 92001, ['title' => 'P1 concurrent receipt'])['id'];
    $ownedIds[] = $id;
    $results = raceGraph($id, array_fill(0, 10, 'concurrent-same-key'));
    if (!isset($results[0]['ok'])) echo 'RACE_DIAGNOSTIC ', json_encode($results), PHP_EOL;
    agentCheck(isset($results[0]['ok']), 'G03 first concurrent request succeeds');
    agentCheck(count(array_filter($results, static fn($result) => $result === $results[0])) === 10, 'G03 ten independent processes return identical receipt');
    $row = Db::name(Graph::TABLE)->where('id', $id)->find();
    agentCheck((int)$row['graph_revision'] === 1 && count(json_decode($row['nodes_json'], true)) === 1, 'G03 concurrent requests create one node and one revision');
    agentCheck(Db::name(Graph::RECEIPTS)->where('canvas_id', $id)->count() === 1, 'G03 concurrent requests persist one receipt');

    $id = Canvas::create(91001, 92001, ['title' => 'P1 concurrent CAS'])['id'];
    $ownedIds[] = $id;
    $results = raceGraph($id, ['writer-a', 'writer-b']);
    agentCheck(count(array_filter($results, static fn($result) => isset($result['ok']))) === 1, 'G01 only one same-base concurrent writer succeeds');
    agentCheck(count(array_filter($results, static fn($result) => ($result['error'] ?? '') === 'VERSION_CONFLICT')) === 1, 'G01 other concurrent writer receives version conflict');
    agentCheck(Db::name(Graph::RECEIPTS)->where('canvas_id', $id)->count() === 1, 'G01 rejected writer does not leave receipt');
} finally {
    // Transactions cannot cover independent processes. Remove only exact rows
    // created by this invocation, always scoped to isolated fixture ownership.
    foreach ($ownedIds as $id) {
        Db::name(Graph::RECEIPTS)->where(['canvas_id' => $id, 'tenant_id' => 91001, 'user_id' => 92001])->delete();
        Db::name(Graph::TABLE)->where(['id' => $id, 'tenant_id' => 91001, 'user_id' => 92001])->delete();
    }
}
echo "NOT_RUN browser concurrent saves and existing background writers; GraphService remains unexposed\n";
