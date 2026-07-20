<?php

namespace app\common\service\power;

/** Application API facade for Wan, Seedance and Happy Horse market products. */
class MarketVideoAppRuntimeService
{
    public static function options(int $tenantId): array { return MarketVideoRuntimeService::options($tenantId, PowerMarketService::TYPE_APP_API); }
    public static function quote(int $tenantId, array $selection): array { return MarketVideoRuntimeService::quote($tenantId, $selection + ['resource_type' => PowerMarketService::TYPE_APP_API]); }
    public static function effectiveDuration(int $tenantId, array $selection, int $fallback = 0): int { return MarketVideoRuntimeService::effectiveDuration($tenantId, $selection + ['resource_type' => PowerMarketService::TYPE_APP_API], $fallback); }
    public static function reserve(int $tenantId, int $userId, string $appCode, string $action, string $businessTable, string $businessTaskId, array $selection, array $request): array { return MarketVideoRuntimeService::reserve($tenantId, $userId, $appCode, $action, $businessTable, $businessTaskId, $selection + ['resource_type' => PowerMarketService::TYPE_APP_API], $request); }
    public static function submit(int $consumptionId, array $request): array { return MarketVideoRuntimeService::submit($consumptionId, $request); }
    public static function refresh(int $consumptionId): array { return MarketVideoRuntimeService::refresh($consumptionId); }
    public static function cancel(int $consumptionId): void { MarketVideoRuntimeService::cancel($consumptionId); }
    public static function fail(int $consumptionId, string $message, string $code = 'failed'): void { MarketVideoRuntimeService::fail($consumptionId, $message, $code); }
    public static function linkBusinessTask(int $appTaskId, int $businessId): void { MarketVideoRuntimeService::linkBusinessTask($appTaskId, $businessId); }
}
