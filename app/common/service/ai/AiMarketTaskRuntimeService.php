<?php

namespace app\common\service\ai;

use app\common\model\ai\AiConsumptionLog;
use app\common\model\power\PowerMarketProduct;
use app\common\service\power\MarketImageModelRuntimeService;
use app\common\service\power\MarketApplicationApiRuntimeService;
use app\common\service\power\MarketVideoRuntimeService;
use app\common\service\power\PowerMarketService;
use RuntimeException;

class AiMarketTaskRuntimeService
{
    public static function refresh(int $consumptionId): void
    {
        $consumption = AiConsumptionLog::findOrEmpty($consumptionId);
        if ($consumption->isEmpty()) {
            return;
        }
        if (self::terminal($consumption->toArray())) {
            self::syncTerminalBusinessResult($consumptionId);
            return;
        }

        $protocol = (string)$consumption['protocol'];
        $provider = (string)$consumption['provider'];
        $snapshot = self::arrayValue($consumption['price_snapshot'] ?? []);
        $upstreamApp = (string)($snapshot['app_code'] ?? '');

        if ($provider === 'power_market' && $protocol === 'application_api') {
            MarketApplicationApiRuntimeService::refresh($consumptionId);
            self::syncTerminalBusinessResult($consumptionId);
            return;
        }

        if ($provider !== 'power_market') {
            AiTaskBusinessResultService::syncByConsumptionId($consumptionId);
            return;
        }

        if ($protocol === 'image_generate' || self::isModelType($snapshot, 'image')) {
            MarketImageModelRuntimeService::refresh($consumptionId);
            self::syncTerminalBusinessResult($consumptionId);
            return;
        }
        if ($protocol === 'video_generate' || self::isVideoApp($upstreamApp) || self::isModelType($snapshot, 'video')) {
            MarketVideoRuntimeService::refresh($consumptionId);
            self::syncTerminalBusinessResult($consumptionId);
            return;
        }
        AiTaskBusinessResultService::syncByConsumptionId($consumptionId);
        $latest = AiConsumptionLog::findOrEmpty($consumptionId);
        if ($latest->isEmpty() || !self::terminal($latest->toArray())) {
            throw new RuntimeException('未注册的市场上游任务处理器: ' . $protocol . '/' . $upstreamApp);
        }
    }

    private static function terminal(array $consumption): bool
    {
        return in_array((string)($consumption['run_status'] ?? ''), ['success', 'failed', 'canceled', 'cancelled'], true)
            || in_array((string)($consumption['billing_status'] ?? ''), ['settled', 'refunded'], true);
    }

    /**
     * Provider runtimes settle the shared consumption before the linked
     * business record is hydrated. Reconcile that handoff here so a late
     * callback or a restarted worker cannot leave the user-facing task active.
     */
    private static function syncTerminalBusinessResult(int $consumptionId): void
    {
        $consumption = AiConsumptionLog::findOrEmpty($consumptionId);
        if ($consumption->isEmpty() || !self::terminal($consumption->toArray())) {
            return;
        }
        AiTaskBusinessResultService::syncTerminalByConsumptionId($consumptionId);
    }

    private static function isVideoApp(string $appCode): bool
    {
        if ($appCode === '') {
            return false;
        }
        static $cache = [];
        if (array_key_exists($appCode, $cache)) {
            return $cache[$appCode];
        }
        $products = PowerMarketProduct::where([
            'resource_type' => PowerMarketService::TYPE_APP_API,
            'upstream_app_code' => $appCode,
            'status' => 1,
        ])->select()->toArray();
        foreach ($products as $product) {
            if (MarketVideoRuntimeService::isSupportedAppProduct($product)) {
                return $cache[$appCode] = true;
            }
        }
        return $cache[$appCode] = false;
    }

    private static function isModelType(array $snapshot, string $type): bool
    {
        $resourceType = strtolower((string)($snapshot['resource_type'] ?? ''));
        if ($resourceType !== 'model') {
            return false;
        }
        $declared = strtolower((string)($snapshot['model_type'] ?? ''));
        if ($declared !== '') {
            return $declared === $type;
        }
        $productId = (int)($snapshot['product_id'] ?? 0);
        if ($productId <= 0) {
            return false;
        }
        $product = PowerMarketProduct::where(['id' => $productId, 'status' => 1])->findOrEmpty();
        return !$product->isEmpty() && strtolower((string)$product['model_type']) === $type;
    }

    private static function arrayValue(mixed $value): array
    {
        if (is_array($value)) return $value;
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }
}
