<?php

namespace app\common\service\app\aigc_canvas\agent\billing;

final class CanvasAgentQueuePolicyService
{
    public static function resolve(array $estimate, array $params = []): array
    {
        $points = max(0, (float)($estimate['estimated_points'] ?? $estimate['user_charge_points'] ?? 0));
        $estimatedTime = max(0, (int)($estimate['estimated_time'] ?? 0));
        $queueType = $points > 0 ? 'fast_queue' : 'relax_queue';

        if (!empty($params['queue_type']) && in_array((string)$params['queue_type'], ['fast_queue', 'relax_queue'], true)) {
            $queueType = (string)$params['queue_type'];
        }

        return [
            'queue_type' => $queueType,
            'priority' => $queueType === 'fast_queue' ? 'normal' : 'low',
            'estimated_wait_seconds' => self::estimatedWaitSeconds($queueType, $estimatedTime),
            'estimated_time' => $estimatedTime,
            'label' => $queueType === 'fast_queue' ? '快速队列' : '权益队列',
        ];
    }

    private static function estimatedWaitSeconds(string $queueType, int $estimatedTime): int
    {
        $base = $queueType === 'fast_queue' ? 10 : 45;
        return $base + max(0, (int)ceil($estimatedTime / 4));
    }
}
