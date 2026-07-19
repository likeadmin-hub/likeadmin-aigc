<?php

namespace app\common\service\tenant;

use app\common\model\tenant\Tenant;
use app\common\model\tenant\TenantContractRecord;
use app\common\model\tenant\TenantPackage;
use app\common\service\tenant\TenantPackageService;
use RuntimeException;
use think\facade\Db;

class TenantContractService
{
    public const STATUS_UNSIGNED = 0;
    public const STATUS_ACTIVE = 1;
    public const STATUS_EXPIRED = 2;

    public static function open(int $tenantId, int $packageId, int $operatorId = 0, string $remark = '', string $sourceOrderSn = ''): array
    {
        if ($tenantId <= 0 || $packageId <= 0) {
            throw new RuntimeException('请选择租户和套餐');
        }
        $remark = trim($remark);
        if ($remark === '') {
            $remark = '租户套餐开通/续费';
        }

        return Db::transaction(function () use ($tenantId, $packageId, $operatorId, $remark, $sourceOrderSn) {
            $tenant = Tenant::withoutGlobalScope()->where('id', $tenantId)->lock(true)->findOrEmpty();
            if ($tenant->isEmpty()) {
                throw new RuntimeException('租户不存在');
            }
            $package = TenantPackage::where(['id' => $packageId, 'status' => 1])->findOrEmpty();
            if ($package->isEmpty()) {
                throw new RuntimeException('套餐不存在或已停用');
            }

            $now = time();
            $durationDays = max(1, (int)$package['duration_days']);
            $oldExpireTime = (int)($tenant['contract_expire_time'] ?? 0);
            $baseTime = $oldExpireTime > $now && (int)($tenant['contract_status'] ?? 0) === self::STATUS_ACTIVE ? $oldExpireTime : $now;
            $expireTime = $baseTime + $durationDays * 86400;
            $startTime = (int)($tenant['contract_start_time'] ?? 0) > 0 ? (int)$tenant['contract_start_time'] : $now;

            Tenant::withoutGlobalScope()->where('id', $tenantId)->update([
                'contract_package_id' => (int)$package['id'],
                'contract_package_name' => (string)$package['name'],
                'contract_start_time' => $startTime,
                'contract_expire_time' => $expireTime,
                'contract_renew_time' => $now,
                'contract_status' => self::STATUS_ACTIVE,
                'disable' => 0,
                'update_time' => $now,
            ]);

            $record = TenantContractRecord::create([
                'tenant_id' => $tenantId,
                'package_id' => (int)$package['id'],
                'package_name' => (string)$package['name'],
                'duration_days' => $durationDays,
                'price' => (string)$package['sale_price'],
                'start_time' => $baseTime,
                'old_expire_time' => $oldExpireTime,
                'expire_time' => $expireTime,
                'operator_id' => $operatorId,
                'source_order_sn' => $sourceOrderSn,
                'remark' => $remark,
                'create_time' => $now,
            ]);

            TenantPackageService::grantBoundAppPlans($tenantId, (int)$package['id'], $operatorId, $sourceOrderSn ?: ('tenant-contract-' . $record['id']));

            return [
                'record_id' => (int)$record['id'],
                'tenant_id' => $tenantId,
                'package_id' => (int)$package['id'],
                'expire_time' => $expireTime,
            ];
        });
    }

    public static function enforceTenantActive(Tenant $tenant): bool
    {
        if ($tenant->isEmpty()) {
            return false;
        }
        if (self::isSignedExpired($tenant)) {
            self::markExpired((int)$tenant['id']);
            return false;
        }
        return (int)$tenant['disable'] === 0;
    }

    public static function expireSignedTenants(): int
    {
        $now = time();
        return Tenant::withoutGlobalScope()
            ->where('contract_status', self::STATUS_ACTIVE)
            ->where('contract_expire_time', '>', 0)
            ->where('contract_expire_time', '<=', $now)
            ->update([
                'contract_status' => self::STATUS_EXPIRED,
                'disable' => 1,
                'update_time' => $now,
            ]);
    }

    public static function summary(array|Tenant $tenant): array
    {
        $row = $tenant instanceof Tenant ? $tenant->toArray() : $tenant;
        $status = (int)($row['contract_status'] ?? self::STATUS_UNSIGNED);
        $expireTime = (int)($row['contract_expire_time'] ?? 0);
        if ($status === self::STATUS_ACTIVE && $expireTime > 0 && $expireTime <= time()) {
            $status = self::STATUS_EXPIRED;
        }
        $daysLeft = null;
        if ($status !== self::STATUS_UNSIGNED && $expireTime > 0) {
            $daysLeft = (int)ceil(($expireTime - time()) / 86400);
        }
        return [
            'contract_package_id' => (int)($row['contract_package_id'] ?? 0),
            'contract_package_name' => (string)($row['contract_package_name'] ?? ''),
            'contract_start_time' => (int)($row['contract_start_time'] ?? 0),
            'contract_expire_time' => $expireTime,
            'contract_renew_time' => (int)($row['contract_renew_time'] ?? 0),
            'contract_status' => $status,
            'contract_status_desc' => self::statusText($status),
            'contract_days_left' => $daysLeft,
        ];
    }

    private static function isSignedExpired(Tenant $tenant): bool
    {
        return (int)($tenant['contract_status'] ?? 0) === self::STATUS_ACTIVE
            && (int)($tenant['contract_expire_time'] ?? 0) > 0
            && (int)($tenant['contract_expire_time'] ?? 0) <= time();
    }

    private static function markExpired(int $tenantId): void
    {
        Tenant::withoutGlobalScope()->where('id', $tenantId)
            ->where('contract_status', self::STATUS_ACTIVE)
            ->update([
                'contract_status' => self::STATUS_EXPIRED,
                'disable' => 1,
                'update_time' => time(),
            ]);
    }

    private static function statusText(int $status): string
    {
        return match ($status) {
            self::STATUS_ACTIVE => '有效',
            self::STATUS_EXPIRED => '已到期',
            default => '未签约',
        };
    }
}

