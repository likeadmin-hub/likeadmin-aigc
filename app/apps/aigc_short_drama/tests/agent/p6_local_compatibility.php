<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use app\common\service\app\aigc_short_drama\ShortDramaCanvasService;
use app\api\controller\app\aigc_short_drama\CanvasController;
use think\facade\Db;

/** P6 local-only source/install parity and backwards-read evidence. */
$bindingTable = 'la_aigc_short_drama_canvas_binding';
foreach ([dirname(__DIR__, 2) . '/migrations/install.sql', root_path() . 'public/install/db/like.sql'] as $install) {
    agentCheck(!str_contains((string)file_get_contents($install), $bindingTable), 'removed formal-project bridge is not provisioned by ' . basename($install));
}
agentCheck(!method_exists(ShortDramaCanvasService::class, 'bindProject'), 'formal-project binding is absent from the canvas service');
$schema = json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/api_schema.json'), true, 512, JSON_THROW_ON_ERROR);
$retired = ['binding', 'bind', 'writebackSources', 'previewStoryWriteback', 'applyStoryWriteback', 'previewEpisodeWriteback', 'applyEpisodeWriteback', 'previewShotWriteback', 'applyShotWriteback'];
$registered = array_column((array)($schema['apis'] ?? []), 'api_path');
foreach ($retired as $action) {
    agentCheck(!method_exists(CanvasController::class, $action), 'formal-project controller action removed: ' . $action);
    agentCheck(!in_array('app.aigc_short_drama.canvas/' . $action, $registered, true), 'formal-project API schema entry removed: ' . $action);
}
agentCheck(method_exists(CanvasController::class, 'save') && method_exists(CanvasController::class, 'quote'), 'ordinary canvas save and generation routes remain available');

// A legacy graph row has no binding and must remain readable byte-for-byte at
// the JSON document boundary. The fixture is rolled back after the read.
Db::startTrans();
try {
    $nodes = '[{"id":"legacy-text","type":"text","metadata":{"content":"旧画布内容"}}]';
    $edges = '[{"from":"legacy-text","to":"legacy-text-note","type":"annotation"}]';
    $viewport = '{"x":-88,"y":42,"k":0.75}';
    $id = Db::name('aigc_short_drama_canvas')->insertGetId([
        'tenant_id' => 91660, 'user_id' => 92660, 'title' => 'P6 legacy JSON',
        'nodes_json' => $nodes, 'edges_json' => $edges, 'viewport_json' => $viewport,
        'removed_node_ids_json' => '[]', 'graph_revision' => 0, 'schema_version' => 1,
        'create_time' => time(), 'update_time' => time(), 'delete_time' => 0,
    ]);
    $loaded = ShortDramaCanvasService::current(91660, 92660, $id);
    agentCheck(json_encode($loaded['nodes'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) === $nodes, 'O03 old node JSON remains readable without normalization loss');
    agentCheck(json_encode($loaded['edges'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) === $edges, 'O03 old edge JSON remains readable without normalization loss');
    agentCheck(json_encode($loaded['viewport'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) === $viewport, 'O03 old viewport JSON remains readable without normalization loss');
} finally {
    Db::rollback();
}

echo "NOT_RUN O04-O10: feature-toggle history, trace redaction, event reconnect, load percentiles, real model and rollback require their dedicated local scenarios; no Provider was called.\n";
