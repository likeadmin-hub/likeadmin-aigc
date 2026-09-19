<?php
// Run from local develop after app migrations. All fixture writes roll back.
require dirname(__DIR__, 4) . '/vendor/autoload.php';
(new think\App())->initialize();
use app\common\service\app\aigc_short_drama\ShortDramaCanvasService as Canvas;
use app\common\service\app\aigc_short_drama\ShortDramaCanvasPosterJobService as Jobs;
use think\facade\Db;
function checkToolbar($condition, string $label): void {
    if (!$condition) throw new RuntimeException($label);
    echo "PASS $label\n";
}
Db::startTrans();
try {
    $doc = Canvas::create(1, 1, ['title' => 'transactional toolbar test']);
    $id = $doc['id'];
    $nodes = [];
    foreach (['text', 'image', 'video', 'audio'] as $index => $type) {
        $nodeId = (string)($index + 1);
        $nodes[] = ['id' => $nodeId, 'type' => $type, 'metadata' => [], 'x' => 0, 'y' => 0];
        Db::name('aigc_short_drama_canvas_run')->insert([
            'tenant_id' => 1, 'user_id' => 1, 'canvas_id' => $id, 'node_id' => $nodeId,
            'node_type' => $type, 'status' => 'success', 'request_json' => '{}', 'result_json' => '{}',
        ]);
    }
    Canvas::save(1, 1, ['id' => $id, 'nodes' => $nodes]);
    Canvas::save(1, 1, ['id' => $id, 'nodes' => [], 'removed_node_ids' => ['1', '2', '3', '4']]);
    checkToolbar(Canvas::current(1, 1, $id)['nodes'] === [], 'deleted generated nodes stay deleted on reload');
    checkToolbar(Db::name('aigc_short_drama_canvas_run')->where('canvas_id', $id)->count() === 4, 'deletion retains task history');
    Canvas::save(1, 1, ['id' => $id, 'nodes' => [$nodes[0]]]);
    checkToolbar(count(Canvas::current(1, 1, $id)['nodes']) === 1, 'undo can restore a deleted node');
    $job = Jobs::enqueue(1, 1, $id, '3', 'uploads/test.mp4', 'tenant', 'local', '', 1.25);
    Db::name('aigc_short_drama_canvas_poster_job')->where('id', $job)->update(['status' => 'running', 'lease_token' => 'test-lease', 'attempts' => 1]);
    checkToolbar($job === Jobs::enqueue(1, 1, $id, '3', 'uploads/test.mp4', 'tenant', 'local', '', 1.25), 'capture submission is idempotent');
    checkToolbar(Db::name('aigc_short_drama_canvas_poster_job')->where('id', $job)->value('lease_token') === 'test-lease', 'duplicate submit cannot steal worker lease');
    checkToolbar(Jobs::frameStatus(1, 1, $id, '3', $job)['status'] === 'running', 'owner can poll capture');
    foreach ([[2, 1], [1, 2]] as [$tenant, $user]) {
        $denied = false;
        try { Jobs::frameStatus($tenant, $user, $id, '3', $job); } catch (Exception $e) { $denied = true; }
        checkToolbar($denied, 'capture poll rejects other tenant/user');
    }
} finally {
    Db::rollback();
}
