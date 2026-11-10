<?php
// Synthetic public-page and local-file fixtures; no paid model requests.
require dirname(__DIR__) . '/vendor/autoload.php';
use app\common\service\app\aigc_product_video\{ProductVideoAmazon as Amazon, ProductVideoImport as Import, ProductVideoCatalog, ProductVideoService};
use think\facade\{Cache, Db};

$checks = 0;
function aiCheck(bool $ok, string $message): void { global $checks; if (!$ok) throw new RuntimeException($message); $checks++; }
function aiReject(callable $call, string $message): void { try { $call(); } catch (Throwable) { aiCheck(true, $message); return; } throw new RuntimeException($message); }
$base = 'https://www.amazon.com/dp/B012345678?th=1&psc=1';
aiCheck(Amazon::target('http://www.amazon.com/own-product/dp/b012345678/ref=test?tag=tracking&th=1&psc=1#details')['url'] === $base, 'Canonical product URL preserves the variant but drops tracking');
foreach (['https://a.co/own', 'https://amzn.to/own', 'https://www.amazon.de/gp/product/B012345678', 'https://www.amazon.co.jp/gp/aw/d/B012345678'] as $url) aiCheck(Amazon::target($url)['url'] !== '', 'Supported product and short URLs');
foreach (['https://amazon.com.evil.test/dp/B012345678', 'https://user:pass@amazon.com/dp/B012345678', 'https://www.amazon.com/s?k=own', 'https://127.0.0.1/dp/B012345678', 'javascript:alert(1)', 'https://www.amazon.com:8080/dp/B012345678', 'https://www.amazon.com/dp/B012345678\\anything'] as $url) aiReject(fn() => Amazon::target($url), 'Unsafe or non-product link rejected');
aiCheck(Amazon::imageUrl('https://m.media-amazon.com/images/I/own.jpg'), 'Amazon CDN accepted');
foreach (['http://m.media-amazon.com/a.jpg', 'https://m.media-amazon.com.evil.test/a.jpg', 'https://127.0.0.1/a.jpg'] as $url) aiCheck(!Amazon::imageUrl($url), 'Untrusted image source rejected');
aiReject(fn() => Amazon::fetch('https://example.invalid/not-amazon'), 'Redirect destination checked before DNS/HTTP');
$gallery = [];
for ($i = 1; $i <= 9; $i++) $gallery[] = ['hiRes' => $i === 2 ? null : "https://m.media-amazon.com/images/I/own$i._AC_SL1500_.jpg", 'large' => "https://m.media-amazon.com/images/I/own$i._AC_SL1000_.jpg"];
$gallery[] = ['hiRes' => 'https://m.media-amazon.com/images/I/own1._AC_SL500_.jpg'];
$gallery[] = ['hiRes' => 'https://example.invalid/tracker.jpg'];
$html = '<html><span id="productTitle">自有测试保温杯</span><a id="bylineInfo">Visit the Own Cup Store</a>'
    . '<div id="feature-bullets"><ul><li>杯盖可拆卸</li><li>适用于日常饮水</li></ul></div>'
    . '<table id="productDetails_techSpec_section_1"><tr><th>容量</th><td>450 ml</td></tr></table>'
    . '<div id="productDescription">包装包含杯子和杯盖。</div>'
    . '<script type="application/ld+json">{"@type":"Product","name":"无关推荐","image":"https://m.media-amazon.com/images/I/ad.jpg"}</script>'
    . '<script>var data={\'colorImages\':' . json_encode(['initial' => $gallery, 'other-color' => [['hiRes' => 'https://m.media-amazon.com/images/I/other.jpg']]]) . '};</script></html>';
$metadata = Amazon::metadata($html, $base);
aiCheck($metadata['name'] === '自有测试保温杯', 'Current product title wins over unrelated structured data');
aiCheck(count($metadata['images']) === 9, 'Full current gallery is not truncated to six');
aiCheck(str_contains($metadata['images'][1], 'SL1000'), 'Null hiRes falls back to the actual large image');
aiCheck(!str_contains(implode(',', $metadata['images']), 'other.jpg') && !str_contains(implode(',', $metadata['images']), 'ad.jpg'), 'Other variants and recommendations excluded');
foreach (['Own Cup', '杯盖可拆卸', '450 ml', '包装包含', 'B012345678'] as $text) aiCheck(str_contains($metadata['product_info'], $text), 'Product facts filled from page sections');
aiCheck(!str_contains($metadata['product_info'], '\\n'), 'Product details contain real newlines');
$encoded = str_replace("'colorImages':" . json_encode(['initial' => $gallery, 'other-color' => [['hiRes' => 'https://m.media-amazon.com/images/I/other.jpg']]]), "'colorImages':{'initial':A.$.parseJSON('" . str_replace(['\\', "'"], ['\\\\', "\\'"], json_encode($gallery)) . "')}", $html);
aiCheck(count(Amazon::metadata($encoded, $base)['images']) === 9, 'Current parseJSON-wrapped gallery imports every current image without executing scripts');
aiReject(fn() => Amazon::metadata('<title>Robot Check</title>', $base), 'Challenge page cannot be reported as a successful product');
aiCheck(Import::capacity(['max_reference_images' => 1]) === 1 && Import::capacity(['max_reference_images' => 9]) === 9 && Import::capacity(['max_reference_images' => 999]) === 15, 'Capacity comes from a server model with the app ceiling');
aiReject(fn() => Import::capacity(['max_reference_images' => 0]), 'Unloaded/unsupported model cannot invent capacity');
$seed = ['status' => 'pending', 'phase' => 'page', 'source_url' => $base, 'maximum' => 9, 'images' => [], 'cursor' => 0, 'skipped' => 0, 'error' => ''];
// Nine distinct valid PNG byte streams; data after IEND distinguishes fixtures without changing image decoding.
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
$fetches = 0; $stores = 0;
$fetch = static function ($url, $image) use ($base, $html, $png, &$fetches) { $fetches++; return ['url' => $image ? $url : $base, 'body' => $image ? $png . $url : $html]; };
$store = static function ($bytes, $mime) use (&$stores) { $stores++; return ['stored' => true, 'uri' => 'uploads/owned' . $stores . '.png', 'storage_scope' => 'tenant', 'storage_engine' => 'local', 'storage_domain' => '']; };
$session = Import::advance($seed, $fetch, $store);
aiCheck($session['phase'] === 'images' && $stores === 0, 'Page and image reads are separate stages');
for ($i = 0; $i < 9; $i++) $session = Import::advance($session, $fetch, $store);
aiCheck($session['status'] === 'success' && count($session['images']) === 9 && $stores === 9, 'Nine-image model imports nine managed images');
$before = $fetches; Import::advance($session, $fetch, $store); aiCheck($before === $fetches, 'Terminal polling cannot repeat downloads/uploads');
$one = $seed; $one['maximum'] = 1; $one = Import::advance($one, $fetch, $store); $one = Import::advance($one, $fetch, $store);
aiCheck($one['status'] === 'success' && count($one['images']) === 1, 'Single-image model stops after one image');
$partialFetch = static function ($url, $image) use ($fetch) { if ($image && str_contains($url, 'own1.')) throw new RuntimeException('read failure'); return $fetch($url, $image); };
$partial = Import::advance($seed, $partialFetch, $store);
for ($i = 0; $i < 9; $i++) $partial = Import::advance($partial, $partialFetch, $store);
aiCheck($partial['status'] === 'success' && count($partial['images']) === 8 && $partial['skipped'] === 1, 'Partial download failure retains usable results');
$failedFetch = static function () { throw new RuntimeException('private network diagnostic'); };
$failed = Import::advance($seed, $failedFetch, $store);
aiCheck($failed['status'] === 'failed' && !str_contains($failed['error'], 'private'), 'Failure never leaks internal diagnostics');

if (getenv('PRODUCT_VIDEO_LOCAL_TEST') === 'x_cn') {
    $app = new think\App(); $app->initialize();
    if (config('database.connections.mysql.database') !== 'x_cn') throw new RuntimeException('Local database required');
    $models = ProductVideoCatalog::models(1); $modelId = (string)($models[0]['id'] ?? '');
    aiCheck($modelId !== '', 'A real market model is configured');
    $keys = []; $dir = public_path() . 'uploads/pv_import_' . bin2hex(random_bytes(8)); mkdir($dir, 0700, true);
    $path = $dir . '/owned.png'; file_put_contents($path, $png); $uri = 'uploads/' . basename($dir) . '/owned.png';
    Db::startTrans();
    try {
        Db::name('tenant_file')->insert(['tenant_id' => 1, 'source_id' => 1, 'source' => 1, 'type' => 10, 'name' => 'Import fixture', 'uri' => $uri, 'storage_scope' => 'tenant', 'storage_engine' => 'local', 'storage_domain' => '', 'create_time' => time(), 'update_time' => time(), 'delete_time' => 0]);
        $request = ['url' => $base, 'model_id' => $modelId, 'maximum' => 99999];
        $start = Import::handle(1, 1, $request); $key = 'product_video_import_v1:1:1:' . $start['import_id']; $keys[] = $key; $keys[] = 'product_video_import_v1:1:1:active';
        aiCheck($start['status'] === 'pending' && !isset($start['images']), 'Start returns immediately without reading the remote page');
        aiCheck($start['maximum'] === Import::capacity(ProductVideoCatalog::model(1, ['video_selection' => ['model_id' => $modelId]])), 'Forged browser maximum is ignored');
        aiCheck(Import::handle(1, 1, $request)['import_id'] === $start['import_id'], 'Duplicate start returns the active session');
        aiReject(fn() => Import::handle(1, 2, ['import_id' => $start['import_id']]), 'Another user cannot poll this session');
        aiReject(fn() => Import::handle(2, 1, ['import_id' => $start['import_id']]), 'Another tenant cannot poll this session');
        aiReject(fn() => Import::handle(1, 1, ['import_id' => $start['import_id'], 'model_id' => 'different-model']), 'Changed model cannot receive previous results');
        $saved = Cache::get($key); $saved['status'] = 'success'; $saved['phase'] = 'done'; $saved['name'] = '自有测试杯子'; $saved['product_info'] = '已确认商品信息'; $saved['images'] = [['uri' => $uri]]; Cache::set($key, $saved, 60);
        $result = Import::handle(1, 1, ['import_id' => $start['import_id'], 'model_id' => $modelId]);
        aiCheck($result['images'][0]['storage_engine'] === 'local' && isset($result['images'][0]['url']), 'Success returns refreshed owned storage URLs');
        aiCheck(!isset($result['images'][0]['user_id']) && !isset($result['candidates']), 'Public DTO hides internal ownership and raw downloads');
        $saved['images'] = [['uri' => 'uploads/foreign-owner.png']]; Cache::set($key, $saved, 60);
        aiReject(fn() => Import::handle(1, 1, ['import_id' => $start['import_id']]), 'A cached result still revalidates file ownership');
        $saved['created_at'] = time() - 601; Cache::set($key, $saved, 60);
        aiReject(fn() => Import::handle(1, 1, ['import_id' => $start['import_id']]), 'Expired imports cannot resume');
    } finally { Db::rollback(); foreach ($keys as $key) Cache::delete($key); unlink($path); rmdir($dir); }
}
echo 'PASS ' . $checks . " Amazon link, gallery, model capacity, staged import and ownership checks\n";
