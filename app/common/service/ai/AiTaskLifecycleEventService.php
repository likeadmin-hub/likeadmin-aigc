<?php

namespace app\common\service\ai;

use app\common\model\ai\AiConsumptionEvent;

/** Records safe, queryable lifecycle markers without retaining provider payloads. */
class AiTaskLifecycleEventService
{
    private const TERMINAL_SUCCESS = ['success', 'succeeded', 'completed', 'complete', 'done', 'finished'];
    private const RESULT_GRACE_SECONDS = 300;

    public static function record(int $consumptionId, string $type, string $status, array $summary = [], int $attempt = 0, int $httpStatus = 0, int $elapsedMs = 0): void
    {
        try {
            AiConsumptionEvent::create([
                'consumption_id' => max(0, $consumptionId),
                'event_type' => mb_substr($type, 0, 30),
                'event_status' => mb_substr($status, 0, 30),
                'attempt_no' => max(1, $attempt),
                'payload_summary' => self::summary($summary),
                'payload_ciphertext' => '',
                'http_status' => max(0, $httpStatus),
                'elapsed_ms' => max(0, $elapsedMs),
                'create_time' => time(),
            ]);
        } catch (\Throwable) {
            // Observability must never make a provider task fail or retry.
        }
    }

    public static function isTerminalSuccess(string $status): bool
    {
        return in_array(strtolower(trim($status)), self::TERMINAL_SUCCESS, true);
    }

    /**
     * A completed provider task without a usable asset cannot be settled. Give
     * result storage a short grace period, then close it through the existing
     * idempotent failure/refund path instead of polling forever.
     */
    public static function terminalResultMissing(int $consumptionId, string $upstreamStatus, string $upstreamTaskId, int $attempt = 0): bool
    {
        $firstSeen = 0;
        try {
            $firstSeen = (int)(AiConsumptionEvent::where('consumption_id', $consumptionId)
                ->where('event_type', 'terminal_mismatch')
                ->where('event_status', 'pending')
                ->min('create_time') ?: 0);
        } catch (\Throwable) {
            // The event table may be absent on an incompletely upgraded site.
        }

        self::record($consumptionId, 'terminal_mismatch', 'pending', [
            'upstream_task_id' => $upstreamTaskId,
            'upstream_status' => strtolower(trim($upstreamStatus)),
            'result_count' => 0,
            'grace_seconds' => self::RESULT_GRACE_SECONDS,
        ], $attempt);

        return $firstSeen > 0 && time() - $firstSeen >= self::RESULT_GRACE_SECONDS;
    }

    private static function summary(array $summary): array
    {
        $safe = [];
        foreach (array_slice($summary, 0, 24, true) as $key => $value) {
            $name = (string)$key;
            if (preg_match('/(authorization|api[_-]?key|token|secret|signature|password|cookie|prompt|input|content)/i', $name) === 1) {
                $safe[$name] = '[redacted]';
                continue;
            }
            if (is_array($value)) {
                $safe[$name] = ['count' => count($value)];
                continue;
            }
            if (is_object($value)) {
                $safe[$name] = '[object]';
                continue;
            }
            if (is_string($value)) {
                $safe[$name] = mb_substr(trim($value), 0, 300);
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $safe[$name] = $value;
            }
        }
        return $safe;
    }
}
