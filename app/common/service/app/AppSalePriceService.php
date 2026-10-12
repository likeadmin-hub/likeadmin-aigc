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

    public static function resolve(mixed $price, float $marketPrice): float
    {
        return self::normalize($price) ?? $marketPrice;
    }

    public static function imageOverride(mixed $price): array
    {
        $price = self::normalize($price);
        return $price === null ? [] : ['user_unit_price' => $price];
    }
}
