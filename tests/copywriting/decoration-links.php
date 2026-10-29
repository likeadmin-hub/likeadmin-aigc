<?php
require __DIR__.'/lifecycle.php';
use think\facade\Db;
use app\common\service\app\AppFrontendManifestService as Frontend;
use app\common\service\app\aigc_copywriting\CopywritingStore as S;
use app\common\service\app\aigc_copywriting\CopywritingToolService as Tools;

Db::startTrans();
try {
    // This is the service behind tenantapi /app/frontend, used by the real DIY link picker.
    $links = function () use ($tenant) {
        $entries = array_values(array_filter(Frontend::tenantEntries($tenant, 'uniapp'), fn ($entry) => $entry['app_code'] === 'aigc_copywriting'));
        check(count($entries) === 1, 'one built-in mobile entry without tenant installation');
        return $entries[0]['meta']['pages'];
    };
    Db::name('tenant_app')->where(['tenant_id'=>$tenant, 'app_code'=>'aigc_copywriting'])->delete();
    $pages = $links();
    check(count($pages) === count(Tools::lists($tenant, true, 'h5')) + 2, 'homepage history and every published function selectable');
    check($pages[0]['path'] === '/apps/aigc_copywriting/pages/index/index', 'homepage route');
    check($pages[1]['path'] === '/apps/aigc_copywriting/pages/history/index', 'history route');
    $tool = S::table('tool')->where(['tenant_id'=>$tenant,'tool_code'=>'copy_rewrite'])->find();
    $functionLinks = fn ($pages) => array_values(array_filter($pages, fn ($p) => ($p['query']['code'] ?? '') === 'copy_rewrite'));
    $selected = $functionLinks($pages)[0];
    check($selected['path'] === '/apps/aigc_copywriting/pages/tool/index', 'shared function route uses query code');
    check(json_decode(json_encode($selected), true)['query']['code'] === 'copy_rewrite', 'code survives JSON save and reload');
    S::table('tool')->where('id',$tool['id'])->update(['name'=>'租户改名验收']);
    check($functionLinks($links())[0]['name'] === '租户改名验收', 'next picker request reflects tenant edits');
    S::table('tool')->where('id',$tool['id'])->update(['status'=>0]);
    check($functionLinks($links()) === [], 'unavailable functions excluded');
    $created = Tools::create($tenant,1,['name'=>'尚未发布验收','category_id'=>$tool['category_id']]);
    check(!in_array($created['tool_code'], array_column(array_column($links(),'query'),'code'), true), 'unpublished DIY function excluded');
    echo "copywriting decoration links PASS (rolled back; no installation or model calls)\n";
} finally { Db::rollback(); }
