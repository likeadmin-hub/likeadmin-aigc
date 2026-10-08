<?php
/** Installs the picture avatar app on a pinned LOCAL development database only. */
require dirname(__DIR__) . '/vendor/autoload.php';
(new \think\App())->initialize();
$db = \think\facade\Db::connect();
$options = getopt('', ['apply', 'database:', 'tenant:', 'sync-market']);
$database = (string)$db->getConfig('database');
if (!in_array((string)$db->getConfig('hostname'), ['127.0.0.1', 'localhost', '::1'], true)) throw new \RuntimeException('Local development database required');
$summary = ['database' => $database, 'app_code' => 'aigc_pic_lipsync', 'applied' => false];
if (isset($options['apply'])) {
    if ((string)($options['database'] ?? '') !== $database) throw new \RuntimeException('Pin the local database with --database');
    \app\common\service\app\AppRegistryService::installFromLocal('aigc_pic_lipsync');
    $tenantId = (int)($options['tenant'] ?? 0);
    if ($tenantId > 0) {
        if (!$db->name('tenant')->where('id', $tenantId)->find()) throw new \RuntimeException('Local tenant does not exist');
        $tenantApp = $db->name('tenant_app')->where(['tenant_id' => $tenantId, 'app_code' => 'aigc_pic_lipsync'])->find();
        if (!$tenantApp || (string)$tenantApp['enable_status'] !== 'enabled') {
            $plan = $db->name('app_plan')->where(['app_code' => 'aigc_pic_lipsync', 'status' => 1])->order('id','asc')->find();
            if (!$plan || (float)$plan['open_points'] !== 0.0) throw new \RuntimeException('Only the free local preview plan can be opened by this script');
            \app\common\service\app\AppPlanService::openOrRenew($tenantId, 0, 'aigc_pic_lipsync', (int)$plan['id']);
        }
        $summary['tenant_app'] = $db->name('tenant_app')->where(['tenant_id' => $tenantId, 'app_code' => 'aigc_pic_lipsync'])->field('tenant_id,app_code,buy_status,enable_status,shelf_status')->find();
    }
    if (isset($options['sync-market'])) $summary['market_sync'] = \app\common\service\power\PowerMarketService::syncApplicationFromUpstream('pic_lipsync');
    $summary['applied'] = true;
}
$summary['app'] = $db->name('app')->where('code','aigc_pic_lipsync')->field('code,status,current_version')->find();
$summary['frontend_entries'] = $db->name('app_frontend_entry')->where('app_code','aigc_pic_lipsync')->count();
echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
