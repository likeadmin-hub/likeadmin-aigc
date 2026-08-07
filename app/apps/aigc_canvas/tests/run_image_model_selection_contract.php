<?php

declare(strict_types=1);

$root = dirname(__DIR__, 4);
require $root . '/vendor/autoload.php';

use app\common\service\app\aigc_canvas\AigcCanvasService;
use app\common\service\app\aigc_canvas\agent\runtime\AgentLoopService;
use app\common\service\power\MarketImageModelRuntimeService;

$failures = [];
$explicit = new ReflectionMethod(AigcCanvasService::class, 'hasExplicitImageModelSelection');
$explicit->setAccessible(true);
$attempts = new ReflectionMethod(AigcCanvasService::class, 'imageMarketAttemptInputs');
$attempts->setAccessible(true);

if (!$explicit->invoke(null, ['model_selection_explicit' => true, 'channel' => 'market_image_model:99'])) {
    $failures[] = 'an explicitly selected model was not locked';
}
if ($explicit->invoke(null, ['model_selection_explicit' => false, 'channel' => 'market_image_model:1'])) {
    $failures[] = 'a project default was treated as an explicit model selection';
}
if (!$explicit->invoke(null, ['market_sku_id' => 99])) {
    $failures[] = 'a legacy explicit SKU was not locked';
}

$selected = [
    'channel' => 'market_image_model:99',
    'model_id' => 'market_image_model:99',
    'market_product_id' => 99,
    'market_sku_id' => 199,
];
$lockedAttempts = $attempts->invoke(null, 0, $selected, true);
if (count($lockedAttempts) !== 1 || (string)($lockedAttempts[0]['channel'] ?? '') !== 'market_image_model:99') {
    $failures[] = 'an explicit model produced fallback attempts';
}

$merge = new ReflectionMethod(AgentLoopService::class, 'mergeMediaToolOptions');
$merge->setAccessible(true);
$merged = $merge->invoke(null, [
    'channel' => 'market_image_model:99',
    'model_id' => 'market_image_model:99',
    'market_product_id' => 99,
    'market_sku_id' => 199,
    'model_selection_explicit' => true,
], [
    'channel' => 'market_image_model:103',
    'model_id' => 'market_image_model:103',
    'market_product_id' => 103,
    'market_sku_id' => 301,
    'ratio' => '16:9',
]);
if ((string)($merged['channel'] ?? '') !== 'market_image_model:99'
    || (int)($merged['market_sku_id'] ?? 0) !== 199
    || (string)($merged['ratio'] ?? '') !== '16:9') {
    $failures[] = 'the agent loop did not preserve the explicit model preference';
}

$ratioOptions = new ReflectionMethod(MarketImageModelRuntimeService::class, 'ratioOptions');
$ratioOptions->setAccessible(true);
$marketRatios = $ratioOptions->invoke(null, [], [
    'params_schema' => [
        'aspect_ratio' => [
            'options' => ['auto', '1:1', '16:9'],
        ],
    ],
]);
if ($marketRatios !== ['auto', '1:1', '16:9']) {
    $failures[] = 'image model runtime filtered upstream-declared ratio options';
}

$normalizedRatio = new ReflectionMethod(MarketImageModelRuntimeService::class, 'normalizedRatio');
$normalizedRatio->setAccessible(true);
if ($normalizedRatio->invoke(null, 'auto') !== 'auto') {
    $failures[] = 'image model runtime did not preserve upstream auto ratio';
}

$nanoSource = file_get_contents($root . '/app/common/service/power/MarketNanoBananaAppRuntimeService.php') ?: '';
foreach ([
    "'app_code' => self::UPSTREAM_APP_CODE",
    "'api_code' => self::SUBMIT_API_CODE",
    "'available' => true",
    'marketImageModelRatioOptions',
] as $needle) {
    if (!str_contains($nanoSource, $needle)) {
        $failures[] = 'nano banana application option is missing unified catalog field: ' . $needle;
    }
}

$nanoRatios = new ReflectionMethod(\app\common\service\power\MarketNanoBananaAppRuntimeService::class, 'ratioOptions');
$nanoRatios->setAccessible(true);
$splitRatios = $nanoRatios->invoke(null, [
    'source_payload' => [
        'market_metadata' => [
            'params_schema' => [
                'aspect_ratio' => [
                    'options' => 'auto / 1:1 / 16:9',
                ],
            ],
        ],
    ],
], 'nano-banana');
if ($splitRatios !== ['auto', '1:1', '16:9']) {
    $failures[] = 'nano banana application did not split upstream ratio schema strings';
}

echo json_encode([
    'passed' => $failures === [],
    'failures' => $failures,
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;

exit($failures === [] ? 0 : 1);
