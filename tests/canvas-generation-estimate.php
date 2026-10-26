<?php
// Standalone boundary regression: no database, provider, storage or billing writes.
namespace app\common\service\app\aigc_canvas {
    final class CanvasModelRouterService
    {
        public static array $seen = [];
        public static bool $available = true;
        public static function applyToToolInput(int $tenantId, string $tool, array $params): array
        {
            if (($params['ratio'] ?? '') === 'adaptive') $params['ratio'] = '1:1';
            return $params;
        }
        public static function toolPricing(int $tenantId, string $tool, array $params): array
        {
            self::$seen = compact('tenantId', 'tool', 'params');
            return ['available' => self::$available, 'user_charge_points' => 26 * ($params['quantity'] ?? 1), 'message' => 'invalid SKU'];
        }
    }
}
namespace {
    require dirname(__DIR__) . '/app/common/service/ai/ReferenceMentionPromptService.php';
    require dirname(__DIR__) . '/app/common/service/app/aigc_canvas/AigcCanvasService.php';
    use app\common\service\app\aigc_canvas\AigcCanvasService as Service;
    use app\common\service\app\aigc_canvas\CanvasModelRouterService as Router;
    function check(bool $condition, string $label): void {
        if (!$condition) throw new \RuntimeException($label);
        echo "PASS: $label\n";
    }
    $inline = 'data:image/png;base64,dGVzdA==';
    $quote = Service::estimateGeneration(7, 9, [
        'generationKind' => 'image', 'ratio' => 'adaptive', 'count' => 3,
        'reference_images' => [$inline, $inline], 'market_sku_id' => 123,
        'provider_params' => ['seed' => 42],
    ]);
    $request = Router::$seen['params'];
    check($request['ratio'] === '1:1', 'canvas routing precedes quoting');
    check($quote['user_charge_points'] === 78 && $request['quantity'] === 3, 'image quantity reaches pricing');
    check($request['reference_images'] === [$inline], 'quote deduplicates references without persisting inline images');
    check($request['market_sku_id'] === 123 && $request['provider_params']['seed'] === 42, 'SKU and provider parameters survive normalization');
    check(Router::$seen['tenantId'] === 7 && Router::$seen['tool'] === 'generate_image', 'tenant and image pricing route retained');
    Service::estimateGeneration(7, 9, [
        'generationKind' => 'video', 'duration' => 5, 'generate_audio' => 'false',
        'reference_assets' => [['type' => 'video', 'url' => 'https://fixture.invalid/ref.mp4', 'duration' => 26]],
        'reference_images' => [$inline], 'generation_method' => 'omni_reference',
    ]);
    $request = Router::$seen['params'];
    check($request['duration'] === 5 && $request['reference_assets'][0]['duration'] === 26.0, 'output and reference durations remain distinct');
    check($request['generate_audio'] === false && $request['generation_method'] === 'omni_reference', 'video mode and explicit audio option retained');
    check(Router::$seen['tool'] === 'generate_video', 'video pricing route retained');
    Router::$available = false;
    try { Service::estimateGeneration(7, 9, ['generationKind' => 'image']); throw new \LogicException('missing rejection'); }
    catch (\Exception $e) { check($e->getMessage() === 'invalid SKU', 'unavailable quote blocks generation'); }
    try { Service::estimateGeneration(7, 9, ['generationKind' => 'text']); throw new \LogicException('missing rejection'); }
    catch (\Exception $e) { check($e->getMessage() === '不支持的画布生成类型', 'unsupported type rejected'); }
}
