<?php
declare(strict_types=1);

namespace app\common\service\power;

use InvalidArgumentException;
use think\facade\Db;

/** The platform's supply terms for one tenant, applied to each resolved standard rate. */
final class TenantSupplyMarkup
{
    public static function settings(int $tenantId): array
    {
        $defaults = ['tenant_id' => $tenantId, 'rate_percent' => '0.00', 'revision' => 0, 'updated_by' => 0, 'update_time' => 0];
        if ($tenantId === 0) return $defaults;
        if ($tenantId < 0) throw new InvalidArgumentException('租户无效');
        return MarketCatalogReadScope::remember('supply_markup:'.$tenantId, static function () use ($tenantId, $defaults): array {
            $row = Db::name('tenant_power_supply_markup')->where('tenant_id', $tenantId)->find();
            return $row ? [
                'tenant_id' => $tenantId, 'rate_percent' => self::rate($row['rate_percent']),
                'revision' => (int)$row['revision'], 'updated_by' => (int)$row['updated_by'], 'update_time' => (int)$row['update_time'],
            ] : $defaults;
        });
    }

    public static function rate(mixed $value): string
    {
        if ((!is_string($value) && !is_int($value)) || !preg_match('/^\d{1,7}(?:\.\d{1,2})?$/D', (string)$value)) {
            throw new InvalidArgumentException('溢价率须为 0 至 9999999.99 之间的数，最多保留两位小数');
        }
        return bcadd((string)$value, '0', 2);
    }

    public static function apply(mixed $standard, array $markup): string
    {
        $standard = MarketPricePolicy::amount($standard);
        $factor = bcadd('1', bcdiv(self::rate($markup['rate_percent']), '100', 4), 4);
        return MarketPricePolicy::amount(bcmul($standard, $factor, 10));
    }

    public static function price(int $tenantId, mixed $standard): string
    {
        return self::apply($standard, self::settings($tenantId));
    }
}
