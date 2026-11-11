<?php

namespace app\common\service\app;

use InvalidArgumentException;

/** Null inherits the market sale price; an explicit zero is free for the user. */
class AppSalePriceService
{
    public static function normalize(mixed $value): ?float
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        if (!is_numeric($value) || !is_finite((float)$value) || (float)$value < 0) {
            throw new InvalidArgumentException('售价必须为空或非负数');
        }
        return round((float)$value, 2);
    }

    public static function fromRow(array $row): ?float
    {
        // Explicit null must not fall through to a legacy field.
        return self::normalize(array_key_exists('unit_price', $row)
            ? $row['unit_price'] : ($row['tenant_unit_price'] ?? null));
    }

    public static function resolve(mixed $price, float $marketPrice, bool $followMarket = false): float
    {
        return $followMarket ? $marketPrice : (self::normalize($price) ?? $marketPrice);
    }

    /** These apps submit one output per native request; meter totals include reference surcharges. */
    public static function singleImageCost(array $quote): float
    {
        return self::imageAmount($quote, 'tenant_cost_points', 'platform_unit_cost');
    }

    public static function singleImageSalePrice(mixed $price, array $quote): float
    {
        $configured = self::normalize($price);
        if (empty($quote['follow_market_prices']) && $configured !== null) return $configured;
        return self::imageAmount($quote, 'user_charge_points', 'tenant_unit_price');
    }

    private static function imageAmount(array $quote, string $total, string $legacyUnit): float
    {
        if (($quote['settlement_mode'] ?? 'reserved') !== 'reserved') throw new InvalidArgumentException('该工具需要可预估的图片模型报价');
        if (isset($quote['quantity']) && (int)$quote['quantity'] !== 1) throw new InvalidArgumentException('需要单张原生请求报价');
        $raw = array_key_exists($total, $quote) ? $quote[$total] : ($quote[$legacyUnit] ?? null);
        if ($raw === null || $raw === '' || !is_numeric($raw) || !is_finite((float)$raw) || (float)$raw < 0) throw new InvalidArgumentException('图片模型完整报价不可用');
        return (float)$raw;
    }

    public static function imageOverride(mixed $price): array
    {
        $price = self::normalize($price);
        return $price === null ? [] : ['user_unit_price' => $price];
    }
}
