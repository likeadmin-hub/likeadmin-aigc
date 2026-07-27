<?php

namespace app\common\service\app\aigc_canvas\agent\billing;

final class CanvasAgentUsageLedgerService
{
    public static function preview(int $tenantId, int $userId, string $toolCode, array $estimate, array $queue = [], array $params = []): array
    {
        $requestId = trim((string)($params['request_id'] ?? ''));
        if ($requestId === '') {
            $requestId = self::requestId($toolCode);
        }

        return [
            'request_id' => $requestId,
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'tool_code' => $toolCode,
            'estimated_points' => (float)($estimate['estimated_points'] ?? $estimate['user_charge_points'] ?? 0),
            'tenant_cost_points' => (float)($estimate['tenant_cost_points'] ?? 0),
            'user_charge_points' => (float)($estimate['user_charge_points'] ?? 0),
            'settlement_mode' => (string)($estimate['settlement_mode'] ?? 'reserved'),
            'queue_type' => (string)($queue['queue_type'] ?? ''),
            'ledger_source' => 'ai_consumption_log',
        ];
    }

    private static function requestId(string $toolCode): string
    {
        return 'canvas_' . $toolCode . '_' . date('YmdHis') . '_' . substr(str_replace('.', '', uniqid('', true)), -8);
    }
}
