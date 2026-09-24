<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

use think\facade\Db;
use app\common\service\app\aigc_short_drama\ShortDramaCanvasService as Canvas;
use app\common\service\app\aigc_short_drama\canvas_agent\GraphService as Graph;
use app\common\service\app\aigc_short_drama\canvas_agent\GenerationIntentService as Intent;

if (!Db::query("SHOW TABLES LIKE 'la_aigc_short_drama_test_provider_receipt'")) {
    throw new RuntimeException('Concurrency fixture table is not installed; refusing to create test canvases');
}

function posterRaceNode(): array
{
    return ['id' => 1, 'type' => 'video', 'x' => 10, 'y' => 20, 'metadata' => [
        'url' => 'uploads/fixtures/race.mp4', 'poster_uri' => 'uploads/fixtures/old.jpg',
        'poster_url' => '/uploads/fixtures/old.jpg', 'poster_status' => 'ready',
    ]];
}

if (($argv[1] ?? '') === 'worker') {
    echo "READY\n";
    if (trim((string)fgets(STDIN)) !== 'GO') throw new RuntimeException('Missing barrier release');
    if ($argv[3] === 'intent-submit') {
        $intent=Intent::reserve(91001,92001,(int)$argv[2],'same-generation-key','1','text',['prompt'=>'Independent process fixture']);
        $claim=Intent::claim(91001,92001,(int)$intent['id']);
        if ($claim) {
            // This is the simulated external acceptance boundary, separate from local run count.
            Db::name('aigc_short_drama_test_provider_receipt')->insert(['tenant_id'=>91001,'user_id'=>92001,'canvas_id'=>(int)$argv[2],'intent_id'=>$intent['id'],'create_time'=>time()]);
            Intent::accepted(91001,92001,(int)$claim['id'],$claim['claim_token'],(int)$claim['fencing_version'],'concurrent-provider-receipt',['content'=>'fixture'],true);
        }
        echo json_encode(['run_id'=>(int)$intent['canvas_run_id'],'submitted'=>$claim!==null]), PHP_EOL;
        exit(0);
    }
    if ($argv[3] === 'poster-save') {
        $node = posterRaceNode();
        $node['x'] = 999;
        Canvas::save(91001, 92001, ['id' => (int)$argv[2], 'nodes' => [$node]]);
        echo "{\"ok\":true}\n";
        exit(0);
    }
    if ($argv[3] === 'poster-project') {
        $project = new ReflectionMethod(\app\common\service\app\aigc_short_drama\ShortDramaCanvasPosterJobService::class, 'updateCanvasNodePoster');
        $project->setAccessible(true);
        $project->invoke(null, ['canvas_id' => (int)$argv[2], 'tenant_id' => 91001, 'user_id' => 92001, 'node_id' => '1', 'video_uri' => 'uploads/fixtures/race.mp4'],
            ['uri' => 'uploads/fixtures/new.jpg', 'storage_scope' => 'tenant', 'storage_engine' => 'local', 'storage_domain' => '']);
        echo "{\"ok\":true}\n";
        exit(0);
    }
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

    $id = Canvas::create(91001, 92001, ['title' => 'P1 actual save/poster projection race'])['id'];
    $ownedIds[] = $id;
    Db::name(Graph::TABLE)->where('id', $id)->update(['nodes_json' => json_encode([posterRaceNode()])]);
    $results = raceGraph($id, ['poster-save', 'poster-project']);
    agentCheck($results === [['ok' => true], ['ok' => true]], 'G11 actual save and poster projection complete in independent processes');
    $node = Canvas::current(91001, 92001, $id)['nodes'][0];
    agentCheck($node['x'] === 999, 'G11 concurrent poster projection preserves saved position');
    agentCheck($node['metadata']['poster_uri'] === 'uploads/fixtures/new.jpg', 'G11 concurrent old snapshot save preserves newly projected poster');

    $id=Canvas::create(91001,92001,['title'=>'P1 generation intent concurrency'])['id'];
    $ownedIds[]=$id;
    Canvas::save(91001,92001,['id'=>$id,'nodes'=>[['id'=>1,'type'=>'text','metadata'=>[]]]]);
    $results=raceGraph($id,array_fill(0,10,'intent-submit'));
    agentCheck(count(array_unique(array_column($results,'run_id')))===1,'ten independent generation requests return one logical run');
    agentCheck(count(array_filter($results,static fn($row)=>$row['submitted']))===1,'only one independent process claims downstream submission');
    agentCheck(Db::name('aigc_short_drama_test_provider_receipt')->where('canvas_id',$id)->count()===1,'simulated provider independently records exactly one acceptance');
    agentCheck(Db::name('aigc_short_drama_canvas_run')->where('canvas_id',$id)->count()===1 && Db::name(Intent::TABLE)->where('canvas_id',$id)->count()===1,'concurrent intent/run inserts are atomic and unique');
} finally {
    // Transactions cannot cover independent processes. Remove only exact rows
    // created by this invocation, always scoped to isolated fixture ownership.
    foreach ($ownedIds as $id) {
        foreach (['aigc_short_drama_test_provider_receipt',Intent::TABLE,'aigc_short_drama_canvas_run'] as $table) Db::name($table)->where(['canvas_id'=>$id,'tenant_id'=>91001,'user_id'=>92001])->delete();
        Db::name(Graph::RECEIPTS)->where(['canvas_id' => $id, 'tenant_id' => 91001, 'user_id' => 92001])->delete();
        Db::name(Graph::TABLE)->where(['id' => $id, 'tenant_id' => 91001, 'user_id' => 92001])->delete();
    }
}
echo "NOT_RUN browser concurrent saves and full poster extraction worker; actual save/projector boundary tested; GraphService remains unexposed\n";
