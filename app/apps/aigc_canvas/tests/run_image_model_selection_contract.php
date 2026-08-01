<?php

declare(strict_types=1);

$root = dirname(__DIR__, 4);
require $root . '/vendor/autoload.php';

use app\common\service\app\aigc_canvas\AigcCanvasService;
use app\common\service\app\aigc_canvas\agent\runtime\AgentLoopService;

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

echo json_encode([
    'passed' => $failures === [],
    'failures' => $failures,
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;

exit($failures === [] ? 0 : 1);
