<?php

namespace app\common\service\power;

/**
 * Shared contract for market SKUs whose final amount is supplied by upstream.
 * Token prices are displayed per billing unit but are never estimated locally.
 */
class MarketUsageSettlementService
{
    public static function isActualUsageSku(array $skuOrSnapshot): bool
    {
        return str_contains(strtolower((string)($skuOrSnapshot['usage_unit'] ?? '')), 'token');
    }

    public static function unitSize(array $skuOrSnapshot): float
    {
        return max(1, (float)($skuOrSnapshot['usage_unit_size'] ?? 1000000));
    }

    public static function price(float $unitPrice, float $quantity, array $skuOrSnapshot): float
    {
        return round(max(0, $unitPrice) * max(0, $quantity) / self::unitSize($skuOrSnapshot), 6);
    }

    /**
     * Reads only provider-reported Token fields. Do not fall back to prompt or
     * response length here: a missing usage record must remain pending.
     */
    public static function tokenUsage(array $payload): float
    {
        $value = self::firstNumber($payload, [
            'total_tokens', 'totalTokens', 'token_count', 'tokenCount',
            'usage_tokens', 'usageTokens', 'tokens',
        ]);
        return $value > 0 ? $value : 0.0;
    }

    private static function firstNumber(array $value, array $keys): float
    {
        foreach ($keys as $key) {
            if (isset($value[$key]) && is_numeric($value[$key]) && (float)$value[$key] > 0) {
                return (float)$value[$key];
            }
        }
        foreach ($value as $item) {
            if (is_array($item)) {
                $matched = self::firstNumber($item, $keys);
                if ($matched > 0) {
                    return $matched;
                }
            }
        }
        return 0.0;
    }
}
