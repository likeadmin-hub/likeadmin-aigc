<?php
/** Read-only local catalogue inspection. No provider calls, tasks or billing. */
require dirname(__DIR__) . '/runtime-loader.php';
(new AigcRuntimeApp(dirname(__DIR__)))->initialize();

use app\common\model\power\PowerMarketProduct;
use app\common\service\app\aigc_canvas\CanvasModelRouterService;
use app\common\service\power\MarketGenerationCatalogService;
use app\common\service\power\MarketGenerationMaterials;
use app\common\service\power\MarketGenerationSchema;
use app\common\service\power\MarketImageModelRuntimeService;
use app\common\service\power\MarketVideoRuntimeService;

$args = getopt('', ['tenant-id:']);
$tenantRaw = (string)($args['tenant-id'] ?? '');
if (!preg_match('/^[1-9][0-9]*$/', $tenantRaw)) {
    fwrite(STDERR, "Usage: php tools/audit-tool-market-compatibility.php --tenant-id=TENANT_ID\n");
    exit(1);
}
$tenantId = (int)$tenantRaw;
$path = getenv('MARKET_GENERATION_CONTRACTS_PATH') ?: dirname(__DIR__) . '/resources/market/generation-contracts.json';
$contractFile = is_readable($path) ? json_decode((string)file_get_contents($path), true) : null;
$contractModels = is_array($contractFile['models'] ?? null) ? $contractFile['models'] : null;
$canonical = static function ($value) use (&$canonical) {
    if (!is_array($value)) return $value;
    foreach ($value as &$child) $child = $canonical($child);
    unset($child);
    if (!array_is_list($value)) ksort($value);
    return $value;
};
$overview = CanvasModelRouterService::marketOverview($tenantId);
$canvasIds = [];
foreach (['image', 'video'] as $type) {
    $canvasIds[$type] = array_map('strval', array_column((array)($overview[$type]['options'] ?? []), 'id'));
}
$report = [
    'tenant_id' => $tenantId,
    'generated_at' => date(DATE_ATOM),
    'scope' => 'tenant catalog and local metadata only; model visibility and fingerprint do not prove end-to-end compatibility',
    'models' => [],
    'unavailable_applications' => [],
];
foreach (['image', 'video'] as $type) {
    $catalog = MarketGenerationCatalogService::options($tenantId, $type);
    foreach ((array)$catalog['unavailable_applications'] as $item) {
        $report['unavailable_applications'][] = array_intersect_key($item, array_flip(['market_product_id', 'name', 'category_code', 'unavailable_reason']));
    }
    foreach ((array)$catalog['channels'] as $option) {
        $id = (string)($option['id'] ?? '');
        $productId = (int)($option['market_product_id'] ?? 0);
        $resource = (string)($option['resource_type'] ?? '');
        $contractStatus = 'unverified_app_adapter';
        if ($resource === 'model') {
            $contractStatus = 'unknown';
            $product = PowerMarketProduct::where('id', $productId)->find();
            if ($product && $contractModels !== null) {
                // Use the same merged metadata as the respective runtime. Reflection is
                // restricted to this developer tool and never invokes submission.
                $runtime = $type === 'image' ? MarketImageModelRuntimeService::class : MarketVideoRuntimeService::class;
                $metadata = (new ReflectionMethod($runtime, 'metadata'))->invoke(null, $product->toArray());
                $code = (string)($metadata['upstream_metadata']['model_code'] ?? '');
                if ($code === '') $contractStatus = 'missing_model_code';
                elseif (!isset($contractModels[$code])) $contractStatus = 'missing_local_contract';
                else {
                    $fingerprint = hash('sha256', json_encode($canonical([
                        $metadata['params_schema'] ?? [], MarketGenerationMaterials::contract($metadata),
                    ]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                    $expected = (string)($contractModels[$code]['fingerprint'] ?? '');
                    $contractStatus = $expected !== '' && hash_equals($expected, $fingerprint) ? 'matched' : 'changed_or_invalid';
                }
            }
        }
        $fields = [];
        foreach (MarketGenerationSchema::properties(MarketGenerationSchema::array($option['params_schema'] ?? [])) as $field => $definition) {
            $fields[] = ['name' => (string)$field, 'type' => (string)($definition['type'] ?? ''),
                'required' => in_array($field, (array)($option['params_schema']['required'] ?? []), true) || !empty($definition['required'])];
        }
        $modes = [];
        foreach ((array)($option['material_modes'] ?? []) as $mode) {
            $materials = [];
            foreach ((array)($mode['fields'] ?? []) as $field) {
                $materials[] = array_intersect_key($field, array_flip(['field', 'kind', 'role', 'required', 'minimum', 'maximum']));
            }
            $modes[] = ['key' => (string)($mode['key'] ?? ''), 'method' => (string)($mode['method'] ?? ''),
                'native_mode' => (string)($mode['native_mode'] ?? ''), 'materials' => $materials];
        }
        $dramaDefaultGroup = $type === 'video' || $resource === 'model';
        $report['models'][] = [
            'generation_type' => $type, 'id' => $id, 'product_id' => $productId,
            'resource_type' => $resource, 'name' => (string)($option['name'] ?? ''),
            'native_contract' => $contractStatus,
            'tool_catalog_visibility' => [
                'standalone_' . $type => 'listed',
                'canvas' => in_array($id, $canvasIds[$type], true) ? 'listed' : 'not_listed_or_unknown',
                'short_drama' => $dramaDefaultGroup ? 'listed_in_model_group' : 'not_in_default_model_group',
            ],
            'scenario_compatibility' => 'unverified',
            'parameters' => $fields,
            'material_modes' => $modes,
            'reference_limits' => array_intersect_key($option, array_flip(['max_reference_images', 'max_reference_videos', 'max_reference_audios', 'max_reference_assets'])),
            'ratio_options' => $option['ratio_options'] ?? [],
            'duration_options' => $option['duration_options'] ?? [],
            'resolution_options' => $option['resolution_options'] ?? [],
            'sku_ids' => array_values(array_map(static fn($sku) => (int)($sku['market_sku_id'] ?? 0), (array)($option['skus'] ?? []))),
        ];
    }
}
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
