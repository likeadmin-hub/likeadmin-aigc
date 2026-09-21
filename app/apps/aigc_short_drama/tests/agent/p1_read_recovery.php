<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
use think\facade\Db;
use app\common\service\app\aigc_short_drama\ShortDramaCanvasService as Canvas;

Db::startTrans();
try {
    $doc = Canvas::create(91001, 92001, ['title' => 'Read recovery race fixture']);
    $stale = Db::name('aigc_short_drama_canvas')->where('id', $doc['id'])->find();
    $node = ['id' => 1, 'type' => 'text', 'x' => 777, 'y' => 888, 'metadata' => ['content' => 'Concurrent user edit']];
    Db::name('aigc_short_drama_canvas')->where('id', $doc['id'])->update(['nodes_json' => json_encode([$node])]);
    Db::name('aigc_short_drama_canvas_run')->insert(['tenant_id' => 91001, 'user_id' => 92001, 'canvas_id' => $doc['id'], 'node_id' => '2', 'node_type' => 'text', 'status' => 'success', 'request_json' => '{"prompt":"Synthetic recovery"}', 'result_json' => '{}']);
    $method = new ReflectionMethod(Canvas::class, 'formatDocument');
    $method->setAccessible(true);
    $result = $method->invoke(null, $stale, true);
    agentCheck(count($result['nodes']) === 2, 'read recovery merges missing task node with latest saved nodes');
    agentCheck($result['nodes'][0] === $node, 'read recovery preserves concurrent content and coordinates');
    $fresh = Canvas::current(91001, 92001, $doc['id']);
    agentCheck($fresh['document_token'] === $result['document_token'], 'recovered document token matches persisted snapshot');
    Db::name('aigc_short_drama_canvas')->where('id', $doc['id'])->update(['nodes_json' => json_encode([$node]), 'removed_node_ids_json' => '["2"]']);
    $result = $method->invoke(null, $stale, true);
    agentCheck(count($result['nodes']) === 1 && $result['removed_node_ids'] === ['2'], 'read recovery consults latest tombstones and does not resurrect deleted task node');
} finally { Db::rollback(); }
echo "NOT_RUN live callback race; deterministic interleaving at actual read-repair boundary\n";
