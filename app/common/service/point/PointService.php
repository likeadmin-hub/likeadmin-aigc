<?php

namespace app\common\service\point;

use think\facade\Db;

class PointService
{
    public static function assertCanConsume(int $tenantId, int $userId, float $points): void
    {
        TenantPointService::assertEnough($tenantId, $points);
        UserPointService::assertEnough($userId, $points);
    }

    public static function assertCanConsumeAmounts(int $tenantId, int $userId, float $tenantPoints, float $userPoints): void
    {
        if ($tenantPoints > 0) {
            TenantPointService::assertEnough($tenantId, $tenantPoints);
        }
        if ($userPoints > 0) {
            UserPointService::assertEnough($userId, $userPoints);
        }
    }

    /**
     * Reserve estimated business points before a paid upstream request is submitted.
     * Reserved points are already unavailable to the account and must later settle or release.
     */
    public static function reserveBusinessAmountsInCurrentTransaction(
        int $tenantId,
        int $userId,
        float $tenantPoints,
        float $userPoints,
        string $sourceSn,
        string $remark = '',
        array $extra = []
    ): void {
        self::consumeBusinessAmountsInCurrentTransaction($tenantId, $userId, $tenantPoints, $userPoints, $sourceSn, $remark, array_merge($extra, [
            'billing_stage' => 'reserved',
        ]));
    }

    /**
     * Convert a reservation to final usage. The current image flow never exceeds
     * the reserved amount, but extra consumption remains explicit for future units.
     */
    public static function settleReservedBusinessAmountsInCurrentTransaction(
        int $tenantId,
        int $userId,
        float $reservedTenantPoints,
        float $reservedUserPoints,
        float $actualTenantPoints,
        float $actualUserPoints,
        string $sourceSn,
        string $remark = '',
        array $extra = []
    ): void {
        $reservedTenantPoints = max(0, $reservedTenantPoints);
        $reservedUserPoints = max(0, $reservedUserPoints);
        $actualTenantPoints = max(0, $actualTenantPoints);
        $actualUserPoints = max(0, $actualUserPoints);
        $refundTenant = round(max(0, $reservedTenantPoints - $actualTenantPoints), 6);
        $refundUser = round(max(0, $reservedUserPoints - $actualUserPoints), 6);
        if ($refundTenant > 0 || $refundUser > 0) {
            self::refundBusinessAmountsInCurrentTransaction($tenantId, $userId, $refundTenant, $refundUser, $sourceSn . '-refund', $remark . '差额退回', array_merge($extra, [
                'billing_stage' => 'settled',
                'reserved_tenant_points' => $reservedTenantPoints,
                'reserved_user_points' => $reservedUserPoints,
                'actual_tenant_points' => $actualTenantPoints,
                'actual_user_points' => $actualUserPoints,
            ]));
        }
        $extraTenant = round(max(0, $actualTenantPoints - $reservedTenantPoints), 6);
        $extraUser = round(max(0, $actualUserPoints - $reservedUserPoints), 6);
        if ($extraTenant > 0 || $extraUser > 0) {
            self::consumeBusinessAmountsInCurrentTransaction($tenantId, $userId, $extraTenant, $extraUser, $sourceSn . '-extra', $remark . '补扣', array_merge($extra, [
                'billing_stage' => 'extra_settled',
            ]));
        }
    }

    public static function releaseReservedBusinessAmountsInCurrentTransaction(
        int $tenantId,
        int $userId,
        float $tenantPoints,
        float $userPoints,
        string $sourceSn,
        string $remark = '',
        array $extra = []
    ): void {
        self::refundBusinessAmountsInCurrentTransaction($tenantId, $userId, $tenantPoints, $userPoints, $sourceSn, $remark, array_merge($extra, [
            'billing_stage' => 'refunded',
        ]));
    }

    public static function consumeBusiness(
        int $tenantId,
        int $userId,
        float $points,
        string $sourceSn,
        string $remark = '',
        array $extra = []
    ): void {
        Db::startTrans();
        try {
            TenantPointService::consume($tenantId, $points, $sourceSn, $remark, $extra);
            UserPointService::consume($userId, $points, $sourceSn, $remark, $extra);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }

    public static function consumeBusinessInCurrentTransaction(
        int $tenantId,
        int $userId,
        float $points,
        string $sourceSn,
        string $remark = '',
        array $extra = []
    ): void {
        TenantPointService::consume($tenantId, $points, $sourceSn, $remark, $extra);
        UserPointService::consume($userId, $points, $sourceSn, $remark, $extra);
    }

    public static function consumeBusinessAmountsInCurrentTransaction(
        int $tenantId,
        int $userId,
        float $tenantPoints,
        float $userPoints,
        string $sourceSn,
        string $remark = '',
        array $extra = []
    ): void {
        if ($tenantPoints > 0) {
            TenantPointService::consume($tenantId, $tenantPoints, $sourceSn, $remark, array_merge($extra, [
                'billing_side' => 'tenant_cost',
            ]));
        }
        if ($userPoints > 0) {
            UserPointService::consume($userId, $userPoints, $sourceSn, $remark, array_merge($extra, [
                'billing_side' => 'user_charge',
            ]));
        }
    }

    public static function refundBusinessAmountsInCurrentTransaction(
        int $tenantId,
        int $userId,
        float $tenantPoints,
        float $userPoints,
        string $sourceSn,
        string $remark = '',
        array $extra = []
    ): void {
        if ($tenantPoints > 0) {
            TenantPointService::refund($tenantId, $tenantPoints, $sourceSn, $remark, array_merge($extra, [
                'billing_side' => 'tenant_cost',
            ]));
        }
        if ($userPoints > 0) {
            UserPointService::refund($userId, $userPoints, $sourceSn, $remark, array_merge($extra, [
                'billing_side' => 'user_charge',
            ]));
        }
    }
}
