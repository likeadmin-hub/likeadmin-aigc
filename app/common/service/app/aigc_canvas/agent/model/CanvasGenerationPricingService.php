<?php

namespace app\common\service\app\aigc_canvas\agent\model;

final class CanvasGenerationPricingService
{
    public static function estimate(int $tenantId, string $toolCode, array $params = []): array
    {
        $toolCode = trim($toolCode);
        $routedParams = CanvasModelRouterService::applyToToolInput($tenantId, $toolCode, $params);
        $pricing = CanvasModelRouterService::toolPricing($tenantId, $toolCode, $routedParams);
        $estimatedPoints = (float)($pricing['user_charge_points'] ?? $pricing['tenant_cost_points'] ?? 0);

        return array_merge($pricing, [
            'tool_code' => $toolCode,
            'model_code' => (string)($routedParams['model_id'] ?? $routedParams['channel'] ?? $routedParams['model'] ?? ''),
            'market_product_id' => (int)($pricing['market_product_id'] ?? $routedParams['market_product_id'] ?? 0),
            'market_sku_id' => (int)($pricing['market_sku_id'] ?? $routedParams['market_sku_id'] ?? $routedParams['sku_id'] ?? 0),
            'quantity' => (float)($pricing['quantity'] ?? $routedParams['quantity'] ?? 1),
            'estimated_points' => $estimatedPoints,
            'estimated_time' => self::estimatedTime($toolCode, $routedParams),
            'requires_confirmation' => self::requiresConfirmation($toolCode, $estimatedPoints, $routedParams),
            'routed_params' => self::publicRoutedParams($routedParams),
        ]);
    }

    public static function estimateMany(int $tenantId, array $items): array
    {
        $result = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $toolCode = (string)($item['tool_code'] ?? $item['tool'] ?? '');
            if ($toolCode === '') {
                continue;
            }
            $result[] = self::estimate($tenantId, $toolCode, (array)($item['params'] ?? $item['input'] ?? $item));
        }
        return $result;
    }

    private static function estimatedTime(string $toolCode, array $params): int
    {
        if ($toolCode === 'generate_video') {
            $duration = max(1, (int)($params['duration'] ?? 5));
            return max(30, $duration * 12);
        }
        if ($toolCode === 'generate_music') {
            $duration = max(30, (int)($params['duration'] ?? 30));
            return max(45, (int)ceil($duration * 1.5));
        }
        if ($toolCode === 'generate_image') {
            return max(20, 20 * max(1, (int)($params['quantity'] ?? 1)));
        }
        return 5;
    }

    private static function requiresConfirmation(string $toolCode, float $estimatedPoints, array $params): bool
    {
        if (!in_array($toolCode, ['generate_image', 'generate_video', 'generate_music'], true)) {
            return false;
        }
        if (!empty($params['requires_confirmation'])) {
            return true;
        }
        // A normal single creation should submit immediately. Confirmation is
        // reserved for a genuinely large batch, not for ordinary point billing.
        $quantity = max(1, (int)($params['quantity'] ?? $params['count'] ?? $params['batch_size'] ?? 1));
        return $quantity > 5;
    }

    private static function publicRoutedParams(array $params): array
    {
        $allowed = [
            'model_id',
            'channel',
            'quality',
            'resolution',
            'ratio',
            'duration',
            'quantity',
            'price_source',
            'market_product_id',
        ];
        return array_intersect_key($params, array_flip($allowed));
    }
}
