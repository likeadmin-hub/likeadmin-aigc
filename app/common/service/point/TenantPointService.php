<?php

namespace app\common\service\point;

use app\common\model\tenant\Tenant;
use app\common\model\tenant\TenantPointLog;
use app\common\service\PointUnitService;
use app\common\service\power\TenantPowerMallService;
use RuntimeException;

class TenantPointService
{
    public const ACTION_INC = 1;
    public const ACTION_DEC = 2;

    public const TYPE_RECHARGE = 'platform_recharge';
    public const TYPE_CONSUME = 'business_consume';
    public const TYPE_REFUND = 'business_refund';
    public const TYPE_ADJUST = 'manual_adjust';

    public static function recharge(int $tenantId, float $points, int $adminId = 0, string $remark = ''): void
    {
        self::assertPositive($points);
        TenantPowerMallService::expireBuckets($tenantId);
        $tenant = self::lockTenant($tenantId);
        $tenant->point_balance = self::formatPoints((float)$tenant['point_balance'] + $points);
        $tenant->save();
        self::log($tenantId, self::TYPE_RECHARGE, self::ACTION_INC, $points, (float)$tenant['point_balance'], '', $remark, [
            'operator_type' => 'platform_admin',
            'operator_id' => $adminId,
        ]);
    }

    public static function consume(int $tenantId, float $points, string $sourceSn, string $remark = '', array $extra = []): void
    {
        self::assertPositive($points);
        if (self::hasLog($tenantId, self::TYPE_CONSUME, self::ACTION_DEC, $sourceSn, $remark)) {
            return;
        }
        TenantPowerMallService::expireBuckets($tenantId);
        $tenant = self::lockTenant($tenantId);
        if (self::hasLog($tenantId, self::TYPE_CONSUME, self::ACTION_DEC, $sourceSn, $remark)) {
            return;
        }
        if ((float)$tenant['point_balance'] < $points) {
            throw new RuntimeException('租户' . PointUnitService::unit() . '不足，请联系管理员');
        }
        $tenant->point_balance = self::formatPoints((float)$tenant['point_balance'] - $points);
        TenantPowerMallService::consumeBuckets($tenantId, $points, $sourceSn, $remark, $extra, false);
        $tenant->save();
        self::log($tenantId, self::TYPE_CONSUME, self::ACTION_DEC, $points, (float)$tenant['point_balance'], $sourceSn, $remark, $extra);
    }

    public static function refund(int $tenantId, float $points, string $sourceSn, string $remark = '', array $extra = []): void
    {
        self::assertPositive($points);
        if (self::hasLog($tenantId, self::TYPE_REFUND, self::ACTION_INC, $sourceSn, $remark)) {
            return;
        }
        TenantPowerMallService::expireBuckets($tenantId);
        $tenant = self::lockTenant($tenantId);
        if (self::hasLog($tenantId, self::TYPE_REFUND, self::ACTION_INC, $sourceSn, $remark)) {
            return;
        }
        $tenant->point_balance = self::formatPoints((float)$tenant['point_balance'] + $points);
        $tenant->save();
        self::log($tenantId, self::TYPE_REFUND, self::ACTION_INC, $points, (float)$tenant['point_balance'], $sourceSn, $remark, $extra);
    }

    public static function assertEnough(int $tenantId, float $points): void
    {
        self::assertPositive($points);
        TenantPowerMallService::expireBuckets($tenantId);
        if (self::balance($tenantId) < $points) {
            throw new RuntimeException('租户' . PointUnitService::unit() . '不足，请联系管理员');
        }
    }

    public static function balance(int $tenantId): float
    {
        return (float)Tenant::where('id', $tenantId)->value('point_balance');
    }

    public static function logs(int $tenantId = 0, int $limit = 50): array
    {
        $query = TenantPointLog::order('id', 'desc')->limit($limit);
        if ($tenantId > 0) {
            $query->where('tenant_id', $tenantId);
        }
        return $query->select()->toArray();
    }

    private static function lockTenant(int $tenantId): Tenant
    {
        $tenant = Tenant::where('id', $tenantId)->lock(true)->findOrEmpty();
        if ($tenant->isEmpty()) {
            throw new RuntimeException('租户不存在');
        }
        return $tenant;
    }

    private static function assertPositive(float $points): void
    {
        if ($points <= 0) {
            throw new RuntimeException(PointUnitService::unit() . '必须大于0');
        }
    }

    private static function hasLog(int $tenantId, string $changeType, int $action, string $sourceSn, string $remark = ''): bool
    {
        if ($sourceSn === '') {
            return false;
        }
        return TenantPointLog::where([
            'tenant_id' => $tenantId,
            'change_type' => $changeType,
            'action' => $action,
            'source_sn' => $sourceSn,
            'remark' => $remark,
        ])->findOrEmpty()->isEmpty() === false;
    }

    private static function formatPoints(float $points): string
    {
        return number_format($points, 2, '.', '');
    }

    private static function log(
        int $tenantId,
        string $changeType,
        int $action,
        float $changeAmount,
        float $leftAmount,
        string $sourceSn = '',
        string $remark = '',
        array $extra = []
    ): void {
        TenantPointLog::create([
            'sn' => generate_sn(TenantPointLog::class, 'sn', 20),
            'tenant_id' => $tenantId,
            'change_type' => $changeType,
            'action' => $action,
            'change_amount' => self::formatPoints($changeAmount),
            'left_amount' => self::formatPoints($leftAmount),
            'source_sn' => $sourceSn,
            'remark' => $remark,
            'extra' => $extra,
            'create_time' => time(),
            'update_time' => time(),
        ]);
    }
}
