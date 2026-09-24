<?php

declare(strict_types=1);

$root = dirname(__DIR__, 4);
require $root . '/vendor/autoload.php';
(new \think\App())->initialize();

use app\common\service\app\aigc_canvas\AigcCanvasService;
use app\common\service\power\MarketImageModelRuntimeService;
use app\common\service\power\MarketImageReferenceUrlService;
use app\common\service\power\MarketNanoBananaAppRuntimeService;

$tenantId = (int)($argv[1] ?? 1);
$failures = [];
$checked = [];
$imagePayload = new ReflectionMethod(MarketImageModelRuntimeService::class, 'payload');
$imagePayload->setAccessible(true);
$nanoPayload = new ReflectionMethod(MarketNanoBananaAppRuntimeService::class, 'payload');
$nanoPayload->setAccessible(true);
$normalizeCanvasImage = new ReflectionMethod(AigcCanvasService::class, 'normalizeImageParams');
$normalizeCanvasImage->setAccessible(true);
$referenceUrl = 'https://example.com/reference.png';

foreach (MarketImageModelRuntimeService::options($tenantId) as $option) {
    if (empty($option['enabled'])) {
        continue;
    }
    $id = (string)$option['id'];
    $selection = [
        'model_id' => $id,
        'market_product_id' => (int)$option['market_product_id'],
        'market_sku_id' => (int)($option['skus'][0]['market_sku_id'] ?? 0),
        'quality' => (string)$option['default_quality'],
        'ratio' => (string)$option['default_ratio'],
    ];
    try {
        $quote = MarketImageModelRuntimeService::quote($tenantId, $selection);
        $payload = $imagePayload->invoke(null, $quote['market_snapshot'], [
            'prompt' => 'dry-run catalog contract',
            'reference_images' => [$referenceUrl, $referenceUrl],
            'quality' => $selection['quality'],
            'ratio' => $selection['ratio'],
            'quantity' => 1,
        ], 'dry-run', 0);
        $checked[] = $id;
        if (($payload['model'] ?? '') !== $option['model_code']) {
            $failures[] = "$id submitted another model";
        }
        if (str_starts_with((string)$option['model_code'], 'qwen-image-3.0')) {
            $content = $payload['input']['messages'][0]['content'] ?? [];
            if (count($content) !== 2 || ($content[0]['image'] ?? '') !== $referenceUrl
                || ($content[1]['text'] ?? '') !== 'dry-run catalog contract'
                || ($payload['parameters']['n'] ?? 0) !== 1
                || empty($payload['parameters']['size'])) {
                $failures[] = "$id structured reference, prompt, count or size is wrong";
            }
        } else {
            $field = (string)$option['reference_input_field'];
            if (($payload[$field] ?? []) !== [$referenceUrl]) {
                $failures[] = "$id reference image was missing or duplicated";
            }
            $schema = (array)($option['params_schema'] ?? []);
            foreach (['resolution', 'image_size'] as $qualityField) {
                if (isset($schema[$qualityField]) && empty($payload[$qualityField])) {
                    $failures[] = "$id omitted documented $qualityField";
                }
                if (!isset($schema[$qualityField]) && isset($payload[$qualityField])) {
                    $failures[] = "$id sent unsupported $qualityField";
                }
            }
        }
        if (isset($payload['task_query']) || isset($payload['_pricing_variant'])) {
            $failures[] = "$id leaked an internal routing field";
        }
    } catch (Throwable $error) {
        $failures[] = "$id failed catalog preflight: {$error->getMessage()}";
    }
}

foreach (MarketNanoBananaAppRuntimeService::options($tenantId) as $option) {
    if (empty($option['enabled'])) {
        continue;
    }
    $id = (string)$option['id'];
    try {
        $quote = MarketNanoBananaAppRuntimeService::quote($tenantId, [
            'model_id' => $id,
            'quality' => (string)$option['default_quality'],
            'ratio' => (string)$option['default_ratio'],
        ]);
        $payload = $nanoPayload->invoke(null, $quote['market_snapshot'], [
            'prompt' => 'dry-run catalog contract',
            'reference_images' => [$referenceUrl, $referenceUrl],
            'ratio' => (string)$option['default_ratio'],
        ], 'dry-run', 0);
        $checked[] = $id;
        if (empty($option['supports_reference_images']) || (int)$option['max_reference_images'] <= 0
            || ($payload['image_urls'] ?? []) !== [$referenceUrl]
            || ($payload['action'] ?? '') !== 'edit'
            || ($payload['model'] ?? '') !== $option['model_code']) {
            $failures[] = "$id reference image capability or edit payload is wrong";
        }
    } catch (Throwable $error) {
        $failures[] = "$id failed catalog preflight: {$error->getMessage()}";
    }
}

$canvasRequest = $normalizeCanvasImage->invoke(null, ['prompt' => 'dry run', 'count' => 2], $tenantId, 0);
if (($canvasRequest['quantity'] ?? 0) !== 2) {
    $failures[] = 'canvas image count did not reach runtime quantity';
}
$localReference = MarketImageReferenceUrlService::resolve(['uploads/catalog-dry-run.png'], 0);
if (count($localReference) !== 1 || preg_match('#^https?://#', $localReference[0]) !== 1) {
    $failures[] = 'a stored relative reference did not become a provider URL';
}

echo json_encode([
    'passed' => $failures === [],
    'checked_count' => count($checked),
    'checked_models' => $checked,
    'failures' => $failures,
    'paid_requests' => 0,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;

exit($failures === [] ? 0 : 1);
