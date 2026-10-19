<?php
declare(strict_types=1);

namespace app\common\service\recharge;

use app\common\service\ConfigService;
use InvalidArgumentException;

/** Custom recharge inputs are points; recharge_ratio is points per yuan. */
final class CustomRechargeService
{
    public static function ratio(): string
    {
        return self::normalizeRatio(ConfigService::get('recharge', 'recharge_ratio', '1'));
    }

    public static function normalizeRatio($ratio): string
    {
        $units = self::hundredths((string)$ratio);
        if ($units <= 0 || $units > 100000000) {
            throw new InvalidArgumentException('充值比例须大于0且不超过1000000，最多保留两位小数');
        }
        return self::decimal($units);
    }

    public static function quote(string $points, string $ratio): array
    {
        $pointUnits = self::hundredths($points);
        $ratioUnits = self::hundredths(self::normalizeRatio($ratio));
        if ($pointUnits <= 0) throw new InvalidArgumentException('请输入大于0的充值算力');
        // Round payment UP to a whole cent; tiny inputs cannot produce a free order.
        $cents = intdiv($pointUnits * 100 + $ratioUnits - 1, $ratioUnits);
        if ($cents > 9999999999) throw new InvalidArgumentException('充值金额超出单笔上限');
        return ['points' => self::decimal($pointUnits), 'amount' => self::decimal($cents)];
    }

    private static function hundredths(string $value): int
    {
        if (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', $value)) {
            throw new InvalidArgumentException('请输入有效数字，最多保留两位小数');
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        return (int)$whole * 100 + (int)str_pad($fraction, 2, '0');
    }

    private static function decimal(int $units): string
    {
        return intdiv($units, 100) . '.' . str_pad((string)($units % 100), 2, '0', STR_PAD_LEFT);
    }
}
