<?php

namespace app\common\service\power;

use app\common\model\power\PowerMarketProduct;
use app\common\model\power\PowerMarketSku;
use app\common\model\power\TenantPowerMarketSkuPrice;
use Exception;

/**
 * GEO application API catalog adapter.
 *
 * The catalog and tenant price are read from the power market. Provider
 * submission is deliberately gated until an upstream GEO API is configured,
 * so a missing provider can never silently consume points.
 */
class MarketGeoAppRuntimeService
{
    public const APP_CODE = 'aigc_geo';
    public const UPSTREAM_APP_CODE = 'aigc_geo';

    public static function options(int $tenantId): array
    {
        $products = PowerMarketProduct::where([
            'resource_type' => PowerMarketService::TYPE_APP_API,
            'upstream_app_code' => self::UPSTREAM_APP_CODE,
            'status' => 1,
        ])->order('id', 'asc')->select()->toArray();
        TenantPowerMarketService::applyProductDisplays($tenantId, $products);
        $options = [];
        foreach ($products as $product) {
            $apiCode = (string)($product['upstream_api_code'] ?? '');
            if ($apiCode === '') {
                continue;
            }
            $metadata = self::arrayValue(self::arrayValue($product['source_payload'] ?? [])['market_metadata'] ?? []);
            $skus = PowerMarketSku::where(['product_id' => (int)$product['id'], 'status' => 1, 'sale_status' => 1])->order('id', 'asc')->select()->toArray();
            foreach ($skus as $sku) {
                $tenantPrice = TenantPowerMarketSkuPrice::where(['tenant_id' => $tenantId, 'sku_id' => (int)$sku['id']])->findOrEmpty();
                if (!$tenantPrice->isEmpty() && (int)$tenantPrice['sale_status'] !== 1) {
                    continue;
                }
                $options[] = [
                    'id' => self::selectionId((int)$product['id'], (int)$sku['id']),
                    'value' => self::selectionId((int)$product['id'], (int)$sku['id']),
                    'market_product_id' => (int)$product['id'],
                    'market_sku_id' => (int)$sku['id'],
                    'sku_id' => (int)$sku['id'],
                    'sku_key' => (string)($sku['sku_key'] ?? ''),
                    'resource_type' => PowerMarketService::TYPE_APP_API,
                    'resource_type_label' => '应用 API',
                    'app_code' => self::UPSTREAM_APP_CODE,
                    'api_code' => $apiCode,
                    'name' => (string)($sku['title'] ?: $product['name']),
                    'description' => (string)($product['description'] ?? ''),
                    'display_icon' => (string)($product['display_icon'] ?? ''),
                    'params_schema' => self::arrayValue($metadata['params_schema'] ?? []),
                    'capabilities' => self::arrayValue($metadata['capabilities'] ?? []),
                    'platform_unit_cost' => self::points((float)$sku['sale_points']),
                    'tenant_unit_price' => self::points($tenantPrice->isEmpty() ? (float)$sku['sale_points'] : (float)$tenantPrice['sale_points']),
                    'usage_unit' => (string)($sku['usage_unit'] ?? 'per_call'),
                    'usage_unit_size' => MarketUsageSettlementService::unitSize($sku),
                    'settlement_mode' => MarketUsageSettlementService::isActualUsageSku($sku) ? 'actual_usage' : 'reserved',
                    'available' => true,
                    'enabled' => true,
                    'status' => 1,
                    'sort' => (int)$sku['id'],
                ];
            }
        }
        return $options;
    }

    public static function isSelection(array $selection): bool
    {
        $value = (string)($selection['id'] ?? $selection['value'] ?? '');
        return str_starts_with($value, 'market_geo_app:') || ((int)($selection['market_product_id'] ?? 0) > 0 && (int)($selection['market_sku_id'] ?? $selection['sku_id'] ?? 0) > 0 && (string)($selection['upstream_app_code'] ?? $selection['app_code'] ?? '') === self::UPSTREAM_APP_CODE);
    }

    public static function quote(int $tenantId, array $selection, int $quantity = 1): array
    {
        $market = self::resolve($tenantId, $selection);
        $quantity = max(1, $quantity);
        $deferred = MarketUsageSettlementService::isActualUsageSku($market['sku']);
        return [
            'billing_unit' => (string)$market['sku']['usage_unit'],
            'billing_unit_size' => MarketUsageSettlementService::unitSize($market['sku']),
            'quantity' => $deferred ? 0 : $quantity,
            'tenant_unit_points' => self::points((float)$market['sku']['sale_points']),
            'user_unit_points' => self::points((float)$market['tenant_price']),
            'tenant_cost_points' => $deferred ? 0 : self::points((float)$market['sku']['sale_points'] * $quantity),
            'user_charge_points' => $deferred ? 0 : self::points((float)$market['tenant_price'] * $quantity),
            'settlement_mode' => $deferred ? 'actual_usage' : 'reserved',
            'price_source' => 'power_market_app_api',
            'market_product_id' => (int)$market['product']['id'],
            'market_sku_id' => (int)$market['sku']['id'],
            'market_snapshot' => [
                'product_id' => (int)$market['product']['id'],
                'sku_id' => (int)$market['sku']['id'],
                'app_code' => self::UPSTREAM_APP_CODE,
                'api_code' => (string)$market['product']['upstream_api_code'],
                'upstream_price' => (float)$market['sku']['upstream_price'],
                'platform_price' => (float)$market['sku']['sale_points'],
                'tenant_price' => (float)$market['tenant_price'],
                'runtime_adapter' => 'geo',
            ],
        ];
    }

    public static function reserve(...$args): array
    {
        throw new Exception('GEO 算力超市应用 API 尚未配置上游提交协议，请先完成 provider 配置');
    }

    public static function submit(...$args): array
    {
        throw new Exception('GEO 算力超市应用 API 尚未配置上游提交协议');
    }

    public static function refresh(...$args): array
    {
        throw new Exception('GEO 算力超市应用 API 尚未配置上游查询协议');
    }

    public static function cancel(...$args): void
    {
        throw new Exception('GEO 算力超市应用 API 尚未配置上游取消协议');
    }

    public static function fail(...$args): void
    {
    }

    private static function resolve(int $tenantId, array $selection): array
    {
        $productId = (int)($selection['market_product_id'] ?? 0);
        $skuId = (int)($selection['market_sku_id'] ?? $selection['sku_id'] ?? 0);
        $id = (string)($selection['id'] ?? $selection['value'] ?? '');
        if (($productId <= 0 || $skuId <= 0) && preg_match('/^market_geo_app:(\d+):(\d+)$/', $id, $matches)) {
            $productId = (int)$matches[1];
            $skuId = (int)$matches[2];
        }
        $product = PowerMarketProduct::where(['id' => $productId, 'resource_type' => PowerMarketService::TYPE_APP_API, 'upstream_app_code' => self::UPSTREAM_APP_CODE, 'status' => 1])->findOrEmpty();
        if ($product->isEmpty()) {
            throw new Exception('所选 GEO 算力超市应用 API 已下架');
        }
        $sku = PowerMarketSku::where(['id' => $skuId, 'product_id' => $productId, 'status' => 1, 'sale_status' => 1])->findOrEmpty();
        if ($sku->isEmpty()) {
            throw new Exception('所选 GEO 算力超市规格已下架');
        }
        $tenant = TenantPowerMarketSkuPrice::where(['tenant_id' => $tenantId, 'sku_id' => $skuId])->findOrEmpty();
        if (!$tenant->isEmpty() && (int)$tenant['sale_status'] !== 1) {
            throw new Exception('所选 GEO 算力超市规格暂不可用');
        }
        return ['product' => $product->toArray(), 'sku' => $sku->toArray(), 'tenant_price' => $tenant->isEmpty() ? (float)$sku['sale_points'] : (float)$tenant['sale_points']];
    }

    private static function selectionId(int $productId, int $skuId): string
    {
        return 'market_geo_app:' . $productId . ':' . $skuId;
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

    private static function points(float $value): float
    {
        return round(max(0, $value), 6);
    }
}
