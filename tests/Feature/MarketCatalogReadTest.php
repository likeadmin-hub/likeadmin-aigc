<?php
require dirname(__DIR__, 2) . '/vendor/autoload.php';
(new think\App())->initialize();

use app\common\model\power\{PowerMarketSku, TenantPowerMarketSkuPrice};
use app\common\service\power\{MarketCatalogReadScope as Scope, MarketCatalogSkuReader as Reader, MarketTextModelRuntimeService as Text};
use think\facade\Db;

function verify($condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
$db = config('database.connections.mysql');
verify($db['hostname'] === '127.0.0.1' && in_array($db['database'], ['x_cn', 'z_cn'], true), 'Local rollback test only');
request()->tenantId = 1;
request()->userId = 0;
request()->userInfo = ['tenant_id' => 1, 'user_id' => 0];

$reads = 0;
$read = function () use (&$reads): array { return [++$reads]; };
Scope::run(function () use ($read): array {
    verify(Scope::remember('tenant:1', $read) === Scope::remember('tenant:1', $read), 'Repeated reads did not share snapshot');
    verify(Scope::remember('tenant:2', $read) !== Scope::remember('tenant:1', $read), 'Tenant keys shared data');
    return [];
});
verify(Scope::remember('tenant:1', $read) === [3], 'Snapshot survived scope exit');
try { Scope::run(function () use ($read): array { Scope::remember('failure', $read); throw new RuntimeException('fixture'); }); } catch (RuntimeException $e) { verify($e->getMessage() === 'fixture', 'Unexpected error'); }
verify(request()->marketCatalogReadScope === null, 'Failed scope leaked snapshot');

Db::startTrans();
try {
    $skus = PowerMarketSku::where(['status' => 1, 'sale_status' => 1])->select()->toArray();
    verify(count($skus) > 0, 'No catalogue fixture');
    foreach ([1, 2] as $tenant) {
        $actual = Reader::forProducts($tenant, array_values(array_unique(array_column($skus, 'product_id'))));
        foreach ($skus as $sku) {
            $price = TenantPowerMarketSkuPrice::where(['tenant_id' => $tenant, 'sku_id' => $sku['id']])->findOrEmpty();
            $available = $actual[(int)$sku['product_id']]['available'] ?? [];
            $found = array_values(array_filter($available, fn($item) => $item['sku']['id'] === $sku['id']));
            verify(count($found) === ($price->isEmpty() || (int)$price['sale_status'] === 1 ? 1 : 0), 'Availability changed');
            if ($found) verify($found[0]['tenant_price'] === ($price->isEmpty() ? (float)$sku['sale_points'] : (float)$price['sale_points']), 'Retail price changed');
        }
    }
    $model = Text::textOptions(1)[0] ?? null;
    verify($model !== null, 'No text-model fixture');
    $skuId = (int)$model['market_input_sku_id'];
    $price = TenantPowerMarketSkuPrice::where(['tenant_id' => 1, 'sku_id' => $skuId])->findOrEmpty();
    $data = ['tenant_id' => 1, 'sku_id' => $skuId, 'sale_status' => 1, 'sale_points' => 123.45];
    if ($price->isEmpty()) TenantPowerMarketSkuPrice::create($data);
    else $price->save($data);
    verify(Text::resolveModel(1, $model['id'])['input']['tenant_price'] === 123.45, 'Admission reused stale catalogue price');
    TenantPowerMarketSkuPrice::where(['tenant_id' => 1, 'sku_id' => $skuId])->update(['sale_status' => 0]);
    $catalogue = Reader::forProducts(1, [(int)$model['product_id']]);
    verify(!in_array($skuId, array_column(array_column($catalogue[(int)$model['product_id']]['available'] ?? [], 'sku'), 'id')), 'Unshelved SKU remained available');
} finally {
    Db::rollback();
}
echo "Market catalogue regression passed: scoped reads, error cleanup, tenant isolation, retail prices and fresh admission; rolled back\n";
