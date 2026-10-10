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
    $where = ['tenant_id'=>1, 'type'=>'system_default', 'name'=>'latest_news'];
    $defaults = json_decode(file_get_contents(dirname(__DIR__, 2) . '/app/common/service/home/latest-news-defaults.json'), true);
    check(array_column($defaults, 'title') === ['热门Nano Banana 应用', '工作流', '局部重绘', '音频创作', 'GPT图像2.5', '电影级镜头视角', '创意应用'], 'seven requested defaults in order');
    \app\common\model\TenantConfig::where($where)->delete();
    check(count(LatestNewsService::lists(1, true)) === 7, 'unconfigured tenant receives seven defaults');
    $legacy = $defaults;
    $legacy[0]['enabled'] = 0;
    $legacy[2]['title'] = 'ImagineArt Inpaint';
    $legacy[] = array_replace($defaults[0], ['id'=>'reference-35', 'title'=>'ImagineArt Teams is Here']);
    $custom = array_replace($defaults[0], ['id'=>'tenant-news', 'title'=>'自己的动态', 'media_url'=>'https://example.com/custom.mp4']);
    $legacy[] = $custom;
    // Seed the old payload directly to exercise existing installations before their next save.
    \app\common\model\TenantConfig::create($where + ['value'=>json_encode($legacy)]);
    $curated = LatestNewsService::lists(1);
    check(count($curated) === 8, 'admin list removes unselected samples and preserves custom news');
    check($curated[2]['title'] === '局部重绘', 'legacy brand title localized');
    check(count(LatestNewsService::lists(1, true)) === 7, 'legacy disabled state preserved');
    check($curated[7]['title'] === $custom['title'], 'tenant title preserved');
    LatestNewsService::save(1, $legacy);
    $saved = json_decode(\app\common\model\TenantConfig::where($where)->value('value'), true);
    check(count($saved) === 8 && $saved[2]['title'] === '局部重绘', 'save persists curated legacy configuration');
    LatestNewsService::save(1, $saved);
    check(json_decode(\app\common\model\TenantConfig::where($where)->value('value'), true) === $saved, 'curation is idempotent');
    $custom['id'] = 'reference-35';
    LatestNewsService::save(1, [$custom]);
    check(count(LatestNewsService::lists(1)) === 1, 'tenant replacement media is not deleted by reserved id alone');
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
