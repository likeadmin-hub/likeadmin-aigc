<?php
/** Read-only real estimate sampling, one first dimension combination per public PC image SKU.
 * Never calls generation, supplier assets, point reservation or market synchronization.
 * Synthetic browser upload shapes are local construction fixtures, not actual browser evidence.
 */
require dirname(__DIR__, 2) . '/runtime-loader.php';
(new AigcRuntimeApp(dirname(__DIR__, 2)))->initialize();

use app\common\service\app\aigc_image\AigcImageChannelService;
use app\common\service\app\aigc_image\AigcImageService;
use app\common\service\power\MarketGenerationImageProbeFixtures;
use think\facade\Db;

$args = getopt('', ['tenant-id:', 'image-role-recipes']);
$tenantRaw = (string)($args['tenant-id'] ?? '');
if (!preg_match('/^[1-9][0-9]*$/D', $tenantRaw)) {
    fwrite(STDERR, "Usage: php tools/market/probe-pc-image-quotes.php --tenant-id=TENANT_ID [--image-role-recipes]\n");
    exit(1);
}
$tenantId = (int)$tenantRaw;
$rows = [];
Db::execute('SET TRANSACTION READ ONLY');
Db::startTrans();
try {
    $config = AigcImageChannelService::userConfig($tenantId);
    foreach ($config['channels'] ?? [] as $channel) {
        if (!in_array($channel['resource_type'] ?? '', ['model', 'app_api'], true)) continue;
        $specs = [];
        foreach ($channel['specs'] ?? [] as $spec) {
            $id = (int)($spec['market_sku_id'] ?? 0);
            if ($id > 0 && !isset($specs[$id])) $specs[$id] = $spec;
        }
        foreach ($specs as $id => $spec) {
            $sku = [];
            foreach ($channel['skus'] ?? [] as $item) {
                if ((int)($item['market_sku_id'] ?? $item['sku_id'] ?? 0) === $id) { $sku = $item; break; }
            }
            $native = (string)($sku['locked_params']['generation_type'] ?? '');
            $mode = [];
            foreach ($channel['material_modes'] ?? [] as $item) {
                if (($item['native_mode'] ?? null) === $native) { $mode = $item; break; }
            }
            $base = ['channel' => $channel['code'], 'name' => $channel['name'], 'requested_sku' => $id,
                'native_mode' => $native, 'material_mode' => (string)($mode['key'] ?? ''),
                'quality' => $spec['quality'], 'ratio' => $spec['ratio']];
            $special = false;
            foreach ($mode['fields'] ?? [] as $field) {
                if (($field['kind'] ?? 'image') !== 'image' || ($field['role'] ?? 'reference') !== 'reference') $special = true;
            }
            $upload = ['id' => 'synthetic-image', 'type' => 'image', 'name' => 'Offline synthetic fixture',
                'url' => 'blob:http://localhost:3000/acceptance-fixture', 'uri' => 'https://fixtures.invalid/upload.png',
                'isObjectUrl' => true, 'uploading' => false];
            $requests = ['text' => []];
            if (!empty($mode['fields'])) $requests = ['browser_upload_dto' => [$upload]];
            elseif (!$mode && (int)($channel['max_reference_images'] ?? 0) > 0) $requests['browser_upload_dto'] = [$upload];
            if ($special) {
                $recipes = array_key_exists('image-role-recipes', $args) ? MarketGenerationImageProbeFixtures::recipes($mode) : null;
                if ($recipes === null) {
                    $rows[] = $base + ['scenario' => 'special_material', 'status' => 'requires_special_material_recipe'];
                    continue;
                }
                $requests = $recipes;
            }
            foreach ($requests as $scenario => $assets) {
                $request = ['channel' => $channel['code'], 'model_id' => $channel['code'], 'market_sku_id' => $id,
                    'quality' => $spec['quality'], 'ratio' => $spec['ratio'], 'prompt' => 'Offline acceptance fixture',
                    'quantity' => 1, 'reference_assets' => $assets,
                    'reference_images' => array_column($assets, 'uri')];
                if ($mode) $request['material_mode'] = $mode['key'];
                try {
                    $quote = AigcImageService::estimate($tenantId, $request);
                    $rows[] = $base + ['scenario' => $scenario, 'status' => 'quoted',
                        'selected_sku' => (int)($quote['market_sku_id'] ?? 0)];
                } catch (Throwable $e) {
                    // Whitelist diagnostic categories. Never export arbitrary exception text or inputs.
                    $message = $e->getMessage();
                    $reason = str_contains($message, '实际像素计费') ? 'requires_verified_pixel_quote' : 'rejected_unclassified';
                    $field = '';
                    if (preg_match('/^请填写必填参数：([A-Za-z_][A-Za-z0-9_.]*)$/uD', $message, $match)) {
                        $reason = 'missing_required_parameter'; $field = $match[1];
                    }
                    $rows[] = $base + ['scenario' => $scenario, 'status' => 'rejected', 'reason' => $reason,
                        'field' => $field, 'error_fingerprint' => hash('sha256', $message)];
                }
            }
        }
    }
} finally {
    Db::rollback();
}
echo json_encode(['version' => 1, 'tenant_id' => $tenantId, 'observed_at' => date(DATE_ATOM),
    'scope' => 'One first dimension combination per public PC image SKU; optional declared flat image/mask recipes; excludes browser UI, opaque structures and generation.',
    'image_role_recipes' => array_key_exists('image-role-recipes', $args),
    'summary' => ['observations' => count($rows), 'statuses' => array_count_values(array_column($rows, 'status')),
        'changed_sku' => count(array_filter($rows, static fn($row) => $row['status'] === 'quoted' && $row['selected_sku'] !== $row['requested_sku']))],
    'observations' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
