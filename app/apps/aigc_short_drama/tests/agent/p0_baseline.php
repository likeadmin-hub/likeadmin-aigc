<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
use app\common\service\app\aigc_short_drama\ShortDramaCanvasService as Canvas;
use think\facade\Db;

Db::startTrans();
try {
    $doc = Canvas::create(91001, 92001, ['title' => 'P0 isolated four-node baseline']);
    $nodes = [];
    foreach (['text', 'image', 'video', 'audio'] as $i => $type) {
        $nodes[] = ['id' => $i + 1, 'type' => $type, 'title' => $type,
            'x' => 120 + $i * 100, 'y' => 80, 'width' => 300, 'height' => 220,
            'metadata' => ['prompt' => 'Synthetic fixture ' . $type, 'content' => $type === 'text' ? 'P0 fixture' : '', 'groupId' => 'p0-group']];
    }
    $edges = [['from' => 1, 'to' => 2], ['from' => 2, 'to' => 3]];
    $viewport = ['x' => 10, 'y' => 20, 'k' => 0.8];
    Canvas::save(91001, 92001, ['id' => $doc['id'], 'nodes' => $nodes, 'edges' => $edges, 'viewport' => $viewport]);
    $loaded = Canvas::current(91001, 92001, $doc['id']);
    agentCheck($loaded['nodes'] === $nodes, 'B02 database roundtrip: four types/content/position/dimensions/groups');
    agentCheck($loaded['edges'] === $edges && $loaded['viewport'] === $viewport, 'B02 legacy from/to references and viewport preserved');
    foreach ([[91001, 92002], [91002, 92003]] as [$tenant, $user]) {
        $rejected = false;
        try { Canvas::current($tenant, $user, $doc['id']); } catch (Exception $e) { $rejected = true; }
        agentCheck($rejected, 'P0 canvas read rejects foreign tenant/user');
        $rejected = false;
        try { Canvas::save($tenant, $user, ['id' => $doc['id'], 'nodes' => []]); } catch (Exception $e) { $rejected = true; }
        agentCheck($rejected, 'P0 canvas save rejects foreign tenant/user');
    }
    // Baseline probes record missing safety instead of disguising it as PASS.
    $first = $loaded;
    $second = $loaded;
    $first['nodes'][0]['metadata']['content'] = 'First writer';
    Canvas::save(91001, 92001, $first);
    $second['nodes'][0]['metadata']['content'] = 'Stale writer';
    Canvas::save(91001, 92001, $second);
    $result = Canvas::current(91001, 92001, $doc['id']);
    echo ($result['nodes'][0]['metadata']['content'] === 'Stale writer' ? 'KNOWN_GAP' : 'CHANGED_BASELINE'), " G01 stale whole-document write accepted\n";
    $overCapacity = [];
    for ($i = 1; $i <= 201; $i++) $overCapacity[] = ['id' => $i, 'type' => 'text', 'metadata' => []];
    $result = Canvas::save(91001, 92001, ['id' => $doc['id'], 'nodes' => $overCapacity]);
    echo 'KNOWN_GAP G08 requested=201 retained=', count($result['nodes']), PHP_EOL;
} finally {
    Db::rollback();
}
agentCheck(Db::name('aigc_short_drama_canvas')->where('tenant_id', 91001)->count() === 0, 'isolated fixture transaction rolled back');
echo "NOT_RUN B03 provider receipt counts and actual billing; NOT_RUN B05 middleware app switch; NOT_RUN B02 browser save/reopen\n";
