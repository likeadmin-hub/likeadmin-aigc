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
    $generationPayload = new ReflectionMethod(Canvas::class, 'generationPayload');
    $generationPayload->setAccessible(true);
    $mediaPayload = $generationPayload->invoke(null, 'video', [
        'prompt' => 'test Wan mixed references',
        'reference_assets' => [
            ['type' => 'image', 'url' => 'https://example.com/image.png', 'role' => 'reference'],
            ['type' => 'video', 'url' => 'https://example.com/video.mp4', 'role' => 'reference_image'],
            ['type' => 'audio', 'url' => 'https://example.com/audio.mp3', 'role' => 'reference'],
        ],
    ], 1, 1, $id);
    checkToolbar(array_column($mediaPayload['reference_assets'], 'role') === [
        'reference_image', 'reference_video', 'reference_audio',
    ], 'canvas video payload preserves image, video, and audio reference roles');
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
    $video = ['id' => '5', 'type' => 'video', 'x' => 0, 'y' => 0, 'metadata' => [
        'url' => 'uploads/test-poster-status.mp4', 'video_url' => 'uploads/test-poster-status.mp4',
        'status' => 'success', 'poster_status' => 'pending',
    ]];
    Canvas::save(1, 1, ['id' => $id, 'nodes' => [$video]]);
    $posterJob = Db::name('aigc_short_drama_canvas_poster_job')->where(['canvas_id' => $id, 'node_id' => '5', 'job_kind' => 'poster'])->find();
    checkToolbar((int)($posterJob['id'] ?? 0) > 0, 'video save queues one poster job');
    Db::name('aigc_short_drama_canvas_poster_job')->where('id', (int)$posterJob['id'])->update(['status' => 'dead', 'attempts' => 4]);
    $read = Canvas::current(1, 1, $id);
    $readNode = current(array_values(array_filter($read['nodes'], static fn($item): bool => (string)$item['id'] === '5')));
    checkToolbar(($readNode['metadata']['poster_status'] ?? '') === 'failed', 're-entry reads terminal poster failure instead of pending');
    $saved = Canvas::save(1, 1, ['id' => $id, 'nodes' => [$video]]);
    $savedNode = current(array_values(array_filter($saved['nodes'], static fn($item): bool => (string)$item['id'] === '5')));
    checkToolbar(($savedNode['metadata']['poster_status'] ?? '') === 'failed', 'stale browser save cannot reactivate a dead poster job');
    checkToolbar(Db::name('aigc_short_drama_canvas_poster_job')->where('id', (int)$posterJob['id'])->value('status') === 'dead', 'dead poster job is not resubmitted');
    checkToolbar(Jobs::posterProjection(2, 1, $id, '5', 'uploads/test-poster-status.mp4') === [], 'poster status remains tenant-isolated');
    $completedRunId = Db::name('aigc_short_drama_canvas_run')->insertGetId([
        'tenant_id' => 1, 'user_id' => 1, 'canvas_id' => $id, 'node_id' => '6',
        'node_type' => 'video', 'status' => 'success', 'progress' => 100,
        'request_json' => '{}', 'result_json' => json_encode(['results' => [['url' => 'uploads/test-completed-video.mp4']]]),
    ]);
    $staleVideo = ['id' => '6', 'type' => 'video', 'x' => 0, 'y' => 0, 'metadata' => [
        'canvasRunId' => $completedRunId, 'status' => 'running', 'progress' => 25, 'pending' => true,
    ]];
    Canvas::save(1, 1, ['id' => $id, 'nodes' => [$staleVideo]]);
    $completedRead = Canvas::current(1, 1, $id);
    $completedNode = current(array_values(array_filter($completedRead['nodes'], static fn($item): bool => (string)$item['id'] === '6')));
    checkToolbar(($completedNode['metadata']['status'] ?? '') === 'success' && ($completedNode['metadata']['pending'] ?? true) === false, 'completed run supersedes stale generating snapshot on re-entry');
    checkToolbar(($completedNode['metadata']['video_url'] ?? '') === 'uploads/test-completed-video.mp4', 'completed run restores playable video URL on re-entry');
    $persisted = json_decode((string)Db::name('aigc_short_drama_canvas')->where('id', $id)->value('nodes_json'), true);
    checkToolbar(($persisted[0]['metadata']['status'] ?? '') === 'running', 'read-time projection does not rewrite saved document');
} finally {
    Db::rollback();
}
