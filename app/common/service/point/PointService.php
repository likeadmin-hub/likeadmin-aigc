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
