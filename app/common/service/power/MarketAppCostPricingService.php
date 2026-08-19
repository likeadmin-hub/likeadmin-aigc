<?php

namespace app\common\service\power;

use app\common\model\power\PowerMarketProduct;
use app\common\model\power\PowerMarketSku;
use Exception;

/** Resolves an application's tenant cost from the platform's market SKU. */
class MarketAppCostPricingService
{
    /**
     * The platform SKU is the sole source of the tenant's cost. Tenant-side
     * application pricing is deliberately not read here.
     */
    public static function quote(string $upstreamAppCode, array $selection): array
    {
        $appCode = trim($upstreamAppCode);
        $products = PowerMarketProduct::where([
            'resource_type' => PowerMarketService::TYPE_APP_API,
            'upstream_app_code' => $appCode,
            'status' => 1,
        ])->order(['update_time' => 'desc', 'id' => 'desc'])->select()->toArray();
        $requestedApi = strtolower(trim((string)($selection['api_code'] ?? '')));
        usort($products, static function (array $left, array $right) use ($requestedApi): int {
            $leftScore = self::productScore($left, $requestedApi);
            $rightScore = self::productScore($right, $requestedApi);
            return $rightScore <=> $leftScore;
        });

        foreach ($products as $product) {
            if (self::isInternalApi((string)($product['upstream_api_code'] ?? ''))) {
                continue;
            }
            $skus = PowerMarketSku::where([
                'product_id' => (int)$product['id'],
                'status' => 1,
                'sale_status' => 1,
            ])->order(['sort' => 'desc', 'id' => 'asc'])->select()->toArray();
            $quote = self::quoteFromCatalog($product, $skus, $selection);
            if ($quote !== null) {
                return $quote;
            }
        }

        throw new Exception('总后台未为该应用配置可售的算力成本 SKU');
    }

    /**
     * Pure catalog calculation used by application services and contract
     * tests. `sale_points` is the platform-to-tenant price, never the C-end
     * tenant sale price.
     */
    public static function quoteFromCatalog(array $product, array $skus, array $selection): ?array
    {
        $candidates = [];
        foreach ($skus as $sku) {
            if ((int)($sku['status'] ?? 0) !== 1 || (int)($sku['sale_status'] ?? 0) !== 1) {
                continue;
            }
            $score = self::matchScore($sku, $selection);
            if ($score < 0) {
                continue;
            }
            $candidates[] = ['sku' => $sku, 'score' => $score];
        }
        if ($candidates === []) {
            return null;
        }
        usort($candidates, static function (array $left, array $right): int {
            $score = $right['score'] <=> $left['score'];
            if ($score !== 0) {
                return $score;
            }
            $sort = (int)($right['sku']['sort'] ?? 0) <=> (int)($left['sku']['sort'] ?? 0);
            return $sort !== 0 ? $sort : ((int)($left['sku']['id'] ?? 0) <=> (int)($right['sku']['id'] ?? 0));
        });

        $sku = $candidates[0]['sku'];
        $unitSize = max(1, (float)($sku['usage_unit_size'] ?? 1));
        $quantity = self::quantity($sku, $selection);
        $platformUnitCost = self::points((float)($sku['sale_points'] ?? 0));

        return [
            'price_source' => 'power_market_sku',
            'market_product_id' => (int)($product['id'] ?? 0),
            'market_sku_id' => (int)($sku['id'] ?? 0),
            'billing_unit' => (string)($sku['usage_unit'] ?? 'per_call'),
            'billing_unit_size' => $unitSize,
            'quantity' => $quantity,
            'platform_unit_cost' => $platformUnitCost,
            'tenant_cost_points' => self::points($platformUnitCost * $quantity / $unitSize),
            'market_snapshot' => [
                'product_id' => (int)($product['id'] ?? 0),
                'sku_id' => (int)($sku['id'] ?? 0),
                'sku_key' => (string)($sku['sku_key'] ?? ''),
                'app_code' => (string)($product['upstream_app_code'] ?? ''),
                'api_code' => (string)($product['upstream_api_code'] ?? ''),
                'locked_params' => self::arrayValue($sku['locked_params'] ?? []),
                'usage_unit' => (string)($sku['usage_unit'] ?? 'per_call'),
                'usage_unit_size' => $unitSize,
                'platform_price' => $platformUnitCost,
                'quantity' => $quantity,
            ],
        ];
    }

    private static function matchScore(array $sku, array $selection): int
    {
        $locked = self::arrayValue($sku['locked_params'] ?? []);
        $mode = strtolower(trim((string)($selection['mode'] ?? '')));
        $lockedMode = strtolower(trim((string)($locked['mode'] ?? $locked['generation_mode'] ?? '')));
        if ($mode !== '' && $lockedMode !== '' && $mode !== $lockedMode) {
            return -1;
        }
        $faceCount = (int)($selection['face_count'] ?? 0);
        $lockedFaceCount = (int)($locked['face_count'] ?? $locked['faces'] ?? 0);
        if ($faceCount > 0 && $lockedFaceCount > 0 && $faceCount !== $lockedFaceCount) {
            return -1;
        }

        $duration = max(0, (float)($selection['duration'] ?? 0));
        $lockedDuration = self::duration($locked);
        if ($duration > 0 && $lockedDuration > 0
            && !self::isSecondUnit((string)($sku['usage_unit'] ?? ''))
            && abs($duration - $lockedDuration) > 0.000001) {
            return -1;
        }

        return ($lockedMode === '' ? 0 : 10) + ($lockedFaceCount > 0 ? 5 : 0) + ($lockedDuration > 0 ? 1 : 0);
    }

    private static function quantity(array $sku, array $selection): float
    {
        if (!self::isSecondUnit((string)($sku['usage_unit'] ?? ''))) {
            return 1;
        }
        return max(0.01, (float)($selection['duration'] ?? 0));
    }

    private static function isSecondUnit(string $unit): bool
    {
        $unit = strtolower($unit);
        return str_contains($unit, 'second') || str_contains($unit, 'sec') || str_contains($unit, '秒');
    }

    private static function isInternalApi(string $apiCode): bool
    {
        return preg_match('/(?:^|[_-])(query|status|detail|list|asset|upload|download|delete|remove)(?:$|[_-])/i', trim($apiCode)) === 1;
    }

    private static function productScore(array $product, string $requestedApi): int
    {
        $api = strtolower(trim((string)($product['upstream_api_code'] ?? '')));
        if ($requestedApi !== '' && $api === $requestedApi) {
            return 100;
        }
        return match ($api) {
            'submit' => 20,
            'create' => 10,
            default => 0,
        };
    }

    private static function duration(array $locked): float
    {
        foreach (['duration', 'seconds', 'video_duration'] as $key) {
            if (isset($locked[$key]) && is_numeric($locked[$key])) {
                return max(0, (float)$locked[$key]);
            }
        }
        return 0;
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

    private static function points(float $points): float
    {
        return round(max(0, $points), 2);
    }
}
