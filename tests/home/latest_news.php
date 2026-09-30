<?php
// Run against the local development database; every write is rolled back.
require dirname(__DIR__, 2) . '/vendor/autoload.php';
$app = new \think\App(dirname(__DIR__, 2));
$app->initialize();
use app\common\service\home\LatestNewsService;
use think\facade\Db;
function check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } echo "PASS $message\n"; }
Db::startTrans();
try {
    $row = ['title'=>'测试动态', 'media_type'=>'video', 'media_url'=>'https://example.com/video.mp4', 'enabled'=>1, 'link'=>['kind'=>'internal','path'=>'/ai/image?model=1']];
    LatestNewsService::save(1, [$row]);
    check(count(LatestNewsService::lists(1, true)) === 1, 'save and read');
    check(LatestNewsService::lists(1)[0]['link']['path'] === '/ai/image?model=1', 'internal query preserved');
    $row['enabled'] = 0; LatestNewsService::save(1, [$row]);
    check(count(LatestNewsService::lists(1, true)) === 0, 'disabled items private');
    LatestNewsService::save(1, []);
    check(LatestNewsService::lists(1) === [], 'empty configuration stays empty');
    foreach ([['kind'=>'external','path'=>'javascript:alert(1)'], ['kind'=>'internal','path'=>'//evil.com'], ['kind'=>'internal','path'=>'/\\evil.com']] as $bad) {
        try { LatestNewsService::save(1, [array_replace($row, ['link'=>$bad])]); throw new LogicException('unsafe link accepted'); }
        catch (RuntimeException $e) { echo "PASS unsafe link rejected\n"; }
    }
    try { LatestNewsService::save(1, [array_replace($row,['media_url'=>'https://example.com/file.mp3'])]); throw new LogicException('audio accepted'); }
    catch (RuntimeException $e) { echo "PASS audio rejected\n"; }
    check(LatestNewsService::lists(0) === [], 'missing tenant has no data');
} finally { Db::rollback(); }
