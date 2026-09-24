<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
use think\facade\Db;
use app\common\service\app\aigc_short_drama\ShortDramaCanvasService as Canvas;

Db::startTrans();
try {
    $doc = Canvas::create(91001, 92001, ['title' => 'P1 poster merge fixture']);
    $node = ['id' => 1, 'type' => 'video', 'x' => 10, 'y' => 20, 'metadata' => [
        'url' => 'uploads/fixtures/clip.mp4', 'poster_uri' => 'uploads/fixtures/new.jpg',
        'poster_url' => '/uploads/fixtures/new.jpg', 'poster_status' => 'ready',
    ]];
    Db::name('aigc_short_drama_canvas')->where('id', $doc['id'])->update(['nodes_json' => json_encode([$node])]);
    $stale = $node;
    $stale['x'] = 99;
    $stale['metadata']['poster_uri'] = 'uploads/fixtures/old.jpg';
    $stale['metadata']['poster_url'] = '/uploads/fixtures/old.jpg';
    $result = Canvas::save(91001, 92001, ['id' => $doc['id'], 'nodes' => [$stale]]);
    agentCheck($result['nodes'][0]['x'] === 99, 'G11 stale poster snapshot still saves user movement');
    agentCheck($result['nodes'][0]['metadata'] === $node['metadata'], 'G11 stale nonempty poster cannot overwrite durable poster for same video');
    $stale['metadata']['url'] = 'uploads/fixtures/different.mp4';
    $result = Canvas::save(91001, 92001, ['id' => $doc['id'], 'nodes' => [$stale]]);
    agentCheck($result['nodes'][0]['metadata']['poster_uri'] === 'uploads/fixtures/old.jpg', 'different video does not inherit previous video poster');
    agentCheck(Db::name('aigc_short_drama_canvas_poster_job')->where('canvas_id', $doc['id'])->count() === 0, 'durable poster save does not enqueue duplicate job');
} finally { Db::rollback(); }
echo "NOT_RUN simultaneous real poster worker/browser writes; stale metadata regression only\n";
