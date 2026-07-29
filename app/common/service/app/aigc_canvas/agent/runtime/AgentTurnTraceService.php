<?php

namespace app\common\service\app\aigc_canvas\agent\runtime;

use Throwable;
use think\facade\Db;

/**
 * Persists the user-visible lifecycle of one Agent turn. Failures here must
 * never interrupt creation work, because trace storage is observability only.
 */
final class AgentTurnTraceService
{
    public static function start(
        int $tenantId,
        int $userId,
        int $projectId,
        int $threadId,
        string $requestId,
        string $mode,
        array $input,
        int $startedAtMs = 0,
        int $firstStatusAtMs = 0
    ): int
    {
        try {
            $nowMs = (int)round(microtime(true) * 1000);
            $startedAtMs = $startedAtMs > 0 ? $startedAtMs : $nowMs;
            $payload = [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'project_id' => $projectId,
                'thread_id' => $threadId,
                'request_id' => mb_substr($requestId, 0, 96, 'UTF-8'),
                'status' => 'running',
                'execution_mode' => $mode,
                'iteration_count' => 0,
                'started_at_ms' => $startedAtMs,
                'first_status_at_ms' => $firstStatusAtMs > 0 ? max($startedAtMs, $firstStatusAtMs) : 0,
                'first_token_at' => 0,
                'first_token_at_ms' => 0,
                'completed_at' => 0,
                'completed_at_ms' => 0,
                'fallback_reason' => '',
                'input_json' => self::json($input),
                'output_json' => self::json([]),
                'error' => '',
                'create_time' => time(),
                'update_time' => time(),
                'delete_time' => 0,
            ];
            try {
                return (int)Db::name('aigc_canvas_agent_turn')->insertGetId($payload);
            } catch (Throwable) {
                // The timing migration is additive. Old installations must keep
                // recording turns while waiting for their next app upgrade.
                unset($payload['first_status_at_ms']);
                return (int)Db::name('aigc_canvas_agent_turn')->insertGetId($payload);
            }
        } catch (Throwable) {
            return 0;
        }
    }

    public static function event(int $turnId, int $sequence, string $type, array $payload): void
    {
        if ($turnId <= 0) {
            return;
        }
        try {
            Db::name('aigc_canvas_agent_turn_event')->insert([
                'turn_id' => $turnId,
                'sequence' => $sequence,
                'event_type' => mb_substr($type, 0, 80, 'UTF-8'),
                'payload_json' => self::json(self::sanitize($payload)),
                'create_time' => time(),
                'create_time_ms' => (int)round(microtime(true) * 1000),
            ]);
        } catch (Throwable) {
        }
    }

    public static function firstToken(int $turnId): void
    {
        if ($turnId <= 0) {
            return;
        }
        try {
            Db::name('aigc_canvas_agent_turn')->where('id', $turnId)->where('first_token_at', 0)->update([
                'first_token_at' => time(),
                'first_token_at_ms' => (int)round(microtime(true) * 1000),
                'update_time' => time(),
            ]);
        } catch (Throwable) {
        }
    }

    public static function complete(int $turnId, int $iterations, array $output, string $fallbackReason = ''): void
    {
        self::finish($turnId, 'success', $iterations, $output, '', $fallbackReason);
    }

    public static function fail(int $turnId, int $iterations, string $error): void
    {
        self::finish($turnId, 'failed', $iterations, [], $error);
    }

    public static function cancel(int $turnId, int $iterations, array $output = []): void
    {
        self::finish($turnId, 'canceled', $iterations, $output, '');
    }

    private static function finish(int $turnId, string $status, int $iterations, array $output, string $error = '', string $fallbackReason = ''): void
    {
        if ($turnId <= 0) {
            return;
        }
        try {
            Db::name('aigc_canvas_agent_turn')->where('id', $turnId)->update([
                'status' => $status,
                'iteration_count' => max(0, $iterations),
                'completed_at' => time(),
                'completed_at_ms' => (int)round(microtime(true) * 1000),
                'fallback_reason' => mb_substr($fallbackReason, 0, 500, 'UTF-8'),
                'output_json' => self::json(self::sanitize($output)),
                'error' => mb_substr($error, 0, 2000, 'UTF-8'),
                'update_time' => time(),
            ]);
        } catch (Throwable) {
        }
    }

    private static function sanitize($value)
    {
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                if (in_array(strtolower((string)$key), ['sku', 'sku_id', 'market_sku_id', 'api_key', 'authorization', 'token', 'secret'], true)) {
                    continue;
                }
                $result[$key] = self::sanitize($item);
            }
            return $result;
        }
        if (is_string($value) && mb_strlen($value, 'UTF-8') > 4000) {
            return mb_substr($value, 0, 4000, 'UTF-8') . '...';
        }
        return $value;
    }

    private static function json($value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
