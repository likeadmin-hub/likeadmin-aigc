<?php

namespace app\common\service\power;

use app\common\model\power\PowerMarketSku;
use app\common\model\power\TenantPowerMarketSkuPrice;

/** Batch the same platform availability and tenant retail-price rules. No persistent cache. */
final class MarketCatalogSkuReader
{
    public static function forProducts(int $tenantId, array $productIds, array $order = []): array
    {
        return MarketCatalogReadScope::run(static fn(): array => self::read($tenantId, $productIds, $order));
    }

    private static function read(int $tenantId, array $productIds, array $order): array
    {
        if ($productIds === []) return [];
        $query = PowerMarketSku::whereIn('product_id', $productIds)->where(['status' => 1, 'sale_status' => 1]);
        if ($order !== []) $query->order($order);
        $rows = $query->select()->toArray();
        $prices = [];
        if ($rows !== []) {
            foreach (TenantPowerMarketSkuPrice::where('tenant_id', $tenantId)
                ->whereIn('sku_id', array_column($rows, 'id'))->select()->toArray() as $price) {
                $prices[(int)$price['sku_id']] = $price;
            }
        }
        $groups = [];
        foreach ($rows as $sku) {
            // Discovery validators reuse these exact rows only until the outer read scope exits.
            MarketCatalogReadScope::remember('video_sku:'.$sku['id'], static fn(): array => $sku);
            $configured = $prices[(int)$sku['id']] ?? [];
            MarketCatalogReadScope::remember('video_tenant_price:'.$tenantId.':'.$sku['id'], static fn(): array => $configured);
            $id = (int)$sku['product_id'];
            $groups[$id]['platform_count'] = ($groups[$id]['platform_count'] ?? 0) + 1;
            $price = $prices[(int)$sku['id']] ?? null;
            if ($price !== null && (int)$price['sale_status'] !== 1) continue;
            $resolved = MarketPriceRuleService::tenant($tenantId, $sku, $price);
            if (!$resolved['valid']) continue;
            $groups[$id]['available'][] = ['sku' => $sku, 'tenant_price' => (float)$resolved['tenant']['price']];
        }
        return $groups;
    }
}
