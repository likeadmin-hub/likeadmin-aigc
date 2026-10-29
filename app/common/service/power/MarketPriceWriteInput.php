<?php
declare(strict_types=1);
namespace app\common\service\power;

use RuntimeException;

final class MarketPriceWriteInput
{
    public static function rows(array $prices): array
    {
        $rows = [];
        foreach ($prices as $price) {
            if (!is_array($price)) throw new RuntimeException('计费 SKU 参数无效');
            $id = filter_var($price['sku_id'] ?? $price['id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id || $id < 1 || isset($rows[$id])) throw new RuntimeException('计费 SKU 无效或重复');
            $price['sku_id'] = $id;
            if (empty($price['state_only']) && ($price['manual_override'] ?? true) !== false) {
                $price['sale_points'] = self::amount($price['sale_points'] ?? null);
                if (array_key_exists('expected_sale_points', $price)) self::amount($price['expected_sale_points']);
            }
            $rows[$id] = $price;
        }
        ksort($rows, SORT_NUMERIC);
        return array_values($rows);
    }

    private static function amount($value): float
    {
        if (!is_numeric($value) || !is_finite((float)$value) || (float)$value < 0) throw new RuntimeException('请输入有效的非负价格');
        return round((float)$value, 6);
    }

    public static function assertExpected(array $price, float $current): void
    {
        if (array_key_exists('expected_sale_points', $price) && self::amount($price['expected_sale_points']) !== round($current, 6)) {
            throw new RuntimeException('价格已更新，请刷新后重新确认售价');
        }
    }
}
