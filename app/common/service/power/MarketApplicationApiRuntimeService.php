<?php

namespace app\common\service\power;

use app\common\model\ai\AiConsumptionLog;
use Exception;

/** Stable facade for market application APIs. Adapters own provider payloads. */
class MarketApplicationApiRuntimeService
{
    public static function options(int $tenantId, string $upstreamAppCode): array
    {
        $adapter = self::adapterForSelection(['upstream_app_code' => $upstreamAppCode]);
        if ($adapter === MarketGenericImageAppRuntimeService::class) {
            return $adapter::options($tenantId, $upstreamAppCode);
        }
        return $adapter::options($tenantId);
    }

    /** @return array<string, mixed> */
    public static function optionsByCategory(int $tenantId, string $categoryCode): array
    {
        return MarketGenerationCatalogService::options($tenantId, $categoryCode);
    }

    public static function quote(int $tenantId, array $selection, int $quantity = 1): array
    {
        $adapter = self::adapterForSelection($selection);
        return $adapter === MarketMusicAppRuntimeService::class
            ? $adapter::quote($tenantId, $selection)
            : $adapter::quote($tenantId, $selection, $quantity);
    }

    public static function reserve(
        int $tenantId,
        int $userId,
        string $action,
        string $businessTaskId,
        array $selection,
        array $request,
        int $quantity = 1,
        string $appCode = '',
        string $businessTable = ''
    ): array
    {
        $adapter = self::adapterForSelection($selection);
        if ($adapter === MarketMusicAppRuntimeService::class) {
            return $adapter::reserve(
                $tenantId,
                $userId,
                $businessTaskId,
                $selection,
                $request,
                $appCode !== '' ? $appCode : $adapter::APP_CODE,
                $action,
                $businessTable !== '' ? $businessTable : 'aigc_short_drama_generation_task'
            );
        }
        return $adapter::reserve(
            $tenantId,
            $userId,
            $action,
            $businessTaskId,
            $selection,
            $request,
            $quantity,
            $appCode !== '' ? $appCode : $adapter::APP_CODE,
            $businessTable !== '' ? $businessTable : 'aigc_short_drama_generation_task'
        );
    }

    public static function adapterForSelection(array $selection): string
    {
        $value = (string)($selection['upstream_app_code'] ?? $selection['app_code'] ?? $selection['application_code'] ?? '');
        if ($value === 'nano_banana' || MarketNanoBananaAppRuntimeService::isSelection($selection)) {
            return MarketNanoBananaAppRuntimeService::class;
        }
        if ($value === 'music_generation') {
            return MarketMusicAppRuntimeService::class;
        }
        if ($value === MarketSeedSvcAppRuntimeService::UPSTREAM_APP_CODE) {
            return MarketSeedSvcAppRuntimeService::class;
        }
        if ($value === MarketGeoAppRuntimeService::UPSTREAM_APP_CODE || MarketGeoAppRuntimeService::isSelection($selection)) {
            return MarketGeoAppRuntimeService::class;
        }
        if ($value === MarketGenericImageAppRuntimeService::UPSTREAM_APP_CODE) {
            return MarketGenericImageAppRuntimeService::class;
        }
        if ((string)($selection['runtime_adapter'] ?? '') === 'generic_image') {
            return MarketGenericImageAppRuntimeService::class;
        }
        throw new Exception('No market application API adapter is registered for this selection');
    }

    public static function adapterForConsumption(int $consumptionId): string
    {
        $consumption = AiConsumptionLog::findOrEmpty($consumptionId);
        if ($consumption->isEmpty()) {
            throw new Exception('Market consumption record does not exist');
        }
        $snapshot = self::arrayValue($consumption['price_snapshot'] ?? []);
        return self::adapterForSelection([
            'upstream_app_code' => (string)($snapshot['app_code'] ?? ''),
            'model_id' => (string)($snapshot['model'] ?? ''),
            'category_code' => (string)($snapshot['category_code'] ?? ''),
            'runtime_adapter' => (string)($snapshot['runtime_adapter'] ?? ''),
        ]);
    }

    public static function refresh(int $consumptionId): array
    {
        $adapter = self::adapterForConsumption($consumptionId);
        return $adapter::refresh($consumptionId);
    }

    public static function submit(int $consumptionId, array $request): array
    {
        $adapter = self::adapterForConsumption($consumptionId);
        return $adapter::submit($consumptionId, $request);
    }

    public static function cancel(int $consumptionId): void
    {
        $adapter = self::adapterForConsumption($consumptionId);
        $adapter::cancel($consumptionId);
    }

    public static function fail(int $consumptionId, string $message, string $code = 'failed'): void
    {
        $adapter = self::adapterForConsumption($consumptionId);
        $adapter::fail($consumptionId, $message, $code);
    }

    private static function arrayValue(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }
}
