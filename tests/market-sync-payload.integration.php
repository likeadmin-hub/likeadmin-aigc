<?php
/** Local database regression: large upstream documentation must survive sync losslessly. */
require dirname(__DIR__) . '/runtime-loader.php';
(new AigcRuntimeApp(dirname(__DIR__)))->initialize();

use app\common\model\power\PowerMarketSku;
use app\common\service\power\PowerMarketService;
use think\facade\Db;

$config = config('database.connections.mysql');
if (($config['hostname'] ?? '') !== '127.0.0.1' || ($config['database'] ?? '') !== 'z_cn') {
    throw new RuntimeException('This rollback test only runs against the local z_cn database');
}
$productMethod = new ReflectionMethod(PowerMarketService::class, 'upsertProduct');
$skuMethod = new ReflectionMethod(PowerMarketService::class, 'upsertSku');
$productMethod->setAccessible(true);
$skuMethod->setAccessible(true);
$largeText = str_repeat('模型参数与接口文档 / upstream schema. ', 4000);
$resource = ['model_code' => 'payload-regression-' . bin2hex(random_bytes(6)), 'model_name' => 'Snapshot regression'];
$item = ['type' => 'model', 'resource' => $resource, 'market_metadata' => ['model_type' => 'video', 'developer_doc_content' => $largeText]];
$sku = ['sku_key' => 'large-snapshot', 'title' => 'Snapshot regression', 'price' => ['points' => 10, 'metadata' => $largeText]];
$failures = [];
$before = [Db::name('power_market_product')->count(), Db::name('power_market_sku')->count()];
Db::startTrans();
try {
    $product = $productMethod->invoke(null, 'model', $resource, $item, true);
    $payload = Db::name('power_market_product')->where('id', $product->id)->value('source_payload');
    if (strlen($payload) <= 65535 || json_decode($payload, true)['market_metadata']['developer_doc_content'] !== $largeText) {
        throw new RuntimeException('Large product documentation was truncated');
    }
    $skuMethod->invoke(null, $product->id, $sku, $item, 1);
    $row = PowerMarketSku::where('product_id', $product->id)->findOrEmpty();
    if ($row['source_payload']['price']['metadata'] !== $largeText) {
        throw new RuntimeException('Large SKU metadata was truncated');
    }
    $row->save(['sale_points' => 25, 'sale_status' => 0]);
    $item['market_metadata']['developer_doc_content'] = $largeText . 'updated';
    $productMethod->invoke(null, 'model', $resource, $item, true);
    $sku['price']['points'] = 12;
    $skuMethod->invoke(null, $product->id, $sku, $item, 1);
    $updatedPayload = json_decode(Db::name('power_market_product')->where('id', $product->id)->value('source_payload'), true);
    $updatedSku = PowerMarketSku::where('product_id', $product->id)->findOrEmpty();
    if ($updatedPayload['market_metadata']['developer_doc_content'] !== $largeText . 'updated'
        || $updatedSku['source_payload']['price']['metadata'] !== $largeText
        || (float)$updatedSku['upstream_price'] !== 12.0
        || (float)$updatedSku['configured_sale_points'] !== 25.0 || (int)$updatedSku['sale_status'] !== 0) {
        throw new RuntimeException('Repeat sync lost metadata or changed manual sale settings');
    }
    echo 'PASS large product/SKU insert, update, and manual price/shelf preservation' . PHP_EOL;
} catch (Throwable $e) {
    $failures[] = $e->getMessage();
} finally {
    Db::rollback();
}
if ($before !== [Db::name('power_market_product')->count(), Db::name('power_market_sku')->count()]) {
    $failures[] = 'Rollback did not restore the original rows';
}
foreach ($failures as $failure) {
    fwrite(STDERR, 'FAIL ' . $failure . PHP_EOL);
}
exit($failures ? 1 : 0);
