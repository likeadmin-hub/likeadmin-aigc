<?php

declare(strict_types=1);

namespace app\common\service;

class PointUnitService
{
    public const DEFAULT_UNIT = '算力';

    public static function unit(): string
    {
        $owner = \app\common\enum\AdminTerminalEnum::isPlatform() ? 'platform' : 'tenant:'.(int)request()->tenantId;
        return \app\common\service\database\ListingReadScope::remember('point_unit:'.$owner,
            static fn(): array => ['unit' => self::normalize(ConfigService::get('recharge', 'point_unit', self::DEFAULT_UNIT))])['unit'];
    }

    public static function normalize($value): string
    {
        $unit = trim((string)$value);
        if ($unit === '') {
            return self::DEFAULT_UNIT;
        }
        return mb_substr($unit, 0, 12);
    }

    public static function config(): array
    {
        return [
            'point_unit' => self::unit(),
        ];
    }
}
