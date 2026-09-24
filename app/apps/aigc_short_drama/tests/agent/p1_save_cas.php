<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
use think\facade\Db;
use app\common\service\app\aigc_short_drama\ShortDramaCanvasService as Canvas;

Db::startTrans();
try {
    $doc = Canvas::create(91001, 92001, ['title' => 'CAS fixture']);
    $params = ['id' => $doc['id'], 'expected_document_token' => $doc['document_token'], 'nodes' => [['id' => 1, 'type' => 'text', 'metadata' => ['content' => 'first']]]];
    $first = Canvas::save(91001, 92001, $params);
    agentCheck($first['document_token'] !== $doc['document_token'], 'CAS save returns changed content token');
    $params['nodes'][0]['metadata']['content'] = 'stale';
    $rejected = false;
    try { Canvas::save(91001, 92001, $params); } catch (Exception $e) { $rejected = str_starts_with($e->getMessage(), 'VERSION_CONFLICT:'); }
    agentCheck($rejected, 'same-base second save receives explicit conflict');
    agentCheck(Canvas::current(91001, 92001, $doc['id'])['nodes'][0]['metadata']['content'] === 'first', 'rejected stale save does not overwrite first writer');
    $params['expected_document_token'] = $first['document_token'];
    $second = Canvas::save(91001, 92001, $params);
    agentCheck($second['nodes'][0]['metadata']['content'] === 'stale', 'explicit current-base save remains possible');
    Db::name('aigc_short_drama_canvas')->where('id', $doc['id'])->update(['viewport_json' => '{"x":44,"y":0,"k":1}']);
    $params['expected_document_token'] = $second['document_token'];
    $rejected = false;
    try { Canvas::save(91001, 92001, $params); } catch (Exception $e) { $rejected = str_starts_with($e->getMessage(), 'VERSION_CONFLICT:'); }
    agentCheck($rejected, 'independent persisted writer invalidates save token');
} finally { Db::rollback(); }
echo "NOT_RUN browser race; missing-token legacy clients remain compatible; content tokens do not replace P1 graph revisions\n";
