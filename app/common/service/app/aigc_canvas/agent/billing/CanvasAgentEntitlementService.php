<?php

namespace app\common\service\app\aigc_canvas\agent\billing;

use app\common\service\point\PointService;

final class CanvasAgentEntitlementService
{
    public static function precheck(int $tenantId, int $userId, array $estimate): array
    {
        $available = !empty($estimate['available']);
        $tenantPoints = max(0, (float)($estimate['tenant_cost_points'] ?? 0));
        $userPoints = max(0, (float)($estimate['user_charge_points'] ?? $estimate['estimated_points'] ?? 0));

        if (!$available) {
            return self::result(false, (string)($estimate['message'] ?? '当前资源不可用'), $tenantPoints, $userPoints);
        }

        try {
            PointService::assertCanConsumeAmounts($tenantId, $userId, $tenantPoints, $userPoints);
        } catch (\Throwable $e) {
            return self::result(false, $e->getMessage(), $tenantPoints, $userPoints);
        }

        return self::result(true, '', $tenantPoints, $userPoints);
    }

    private static function result(bool $canSubmit, string $message, float $tenantPoints, float $userPoints): array
    {
        return [
            'can_submit' => $canSubmit,
            'message' => $message,
            'tenant_cost_points' => $tenantPoints,
            'user_charge_points' => $userPoints,
        ];
    }
}
