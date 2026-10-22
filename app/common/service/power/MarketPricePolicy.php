<?php
namespace app\common\service\power;

use InvalidArgumentException;

/** Prices are points per SKU billing unit, never a surcharge per request. */
final class MarketPricePolicy
{
    public static function amount(mixed $value): string
    {
        if (!is_numeric($value) || !is_finite((float)$value) || (float)$value < 0 || (float)$value >= 10000000000) {
            throw new InvalidArgumentException('价格必须是小于 100 亿的非负有限数');
        }
        $text = (string)$value;
        if (!preg_match('/^\d+(?:\.\d+)?$/D', $text)) $text = sprintf('%.12F', (float)$value);
        $rounded = bcadd($text, '0.0000005', 6);
        if (bccomp($rounded, '10000000000', 6) >= 0) throw new InvalidArgumentException('价格超过精度上限');
        return $rounded;
    }

    public static function normalize(string $mode, mixed $value): array
    {
        if (!in_array($mode, ['inherit', 'add', 'percent', 'manual'], true)) throw new InvalidArgumentException('定价方式无效');
        return ['mode' => $mode, 'value' => self::amount($mode === 'inherit' ? 0 : $value)];
    }

    public static function resolve(mixed $base, mixed $legacy, array $rules, int $productId, int $skuId): array
    {
        $base = self::amount($base);
        $chosen = null;
        foreach (['sku' => $skuId, 'product' => $productId, 'global' => 0] as $scope => $id) {
            foreach ($rules as $rule) {
                if ($rule['scope_type'] === $scope && (int)$rule['scope_id'] === $id && $rule['mode'] !== 'inherit') {
                    $chosen = $rule;
                    break 2;
                }
            }
        }
        $price = $chosen === null ? self::amount($legacy) : match ($chosen['mode']) {
            'add' => self::amount(bcadd($base, (string)$chosen['value'], 6)),
            'percent' => self::amount(bcadd($base, bcdiv(bcmul($base, (string)$chosen['value'], 12), '100', 12), 12)),
            'manual' => self::amount($chosen['value']),
        };
        return ['price' => $price, 'base' => $base, 'valid' => bccomp($price, $base, 6) >= 0,
            'rule' => $chosen ?? ['scope_type' => 'sku', 'scope_id' => $skuId, 'mode' => 'legacy', 'value' => self::amount($legacy)]];
    }
}
