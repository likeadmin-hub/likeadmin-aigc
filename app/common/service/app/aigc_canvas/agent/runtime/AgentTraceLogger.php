<?php

namespace app\common\service\app\aigc_canvas\agent\runtime;

use think\facade\Db;

final class AgentTraceLogger
{
    public static function startRun(int $tenantId, int $userId, int $projectId, int $threadId, string $agentCode, array $input, string $requestId = ''): int
    {
        $requestId = mb_substr(trim($requestId), 0, 96, 'UTF-8');
        if ($requestId !== '') {
            $existing = Db::name('aigc_canvas_agent_run')->where([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'request_id' => $requestId,
                'delete_time' => 0,
            ])->find();
            if (!empty($existing)) {
                return (int)$existing['id'];
            }
        }
        return (int)Db::name('aigc_canvas_agent_run')->insertGetId([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => $projectId,
            'thread_id' => $threadId,
            'request_id' => $requestId,
            'agent_code' => $agentCode,
            'status' => 'running',
            'input_json' => json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'output_json' => json_encode([], JSON_UNESCAPED_UNICODE),
            'error' => '',
            'create_time' => time(),
            'update_time' => time(),
            'delete_time' => 0,
        ]);
    }

    public static function findByRequest(int $tenantId, int $userId, string $requestId): array
    {
        $requestId = mb_substr(trim($requestId), 0, 96, 'UTF-8');
        if ($requestId === '') {
            return [];
        }
        $row = Db::name('aigc_canvas_agent_run')->where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'request_id' => $requestId,
            'delete_time' => 0,
        ])->find();
        if (empty($row)) {
            return [];
        }
        $output = json_decode((string)($row['output_json'] ?? ''), true);
        $row['output'] = is_array($output) ? $output : [];
        return $row;
    }

    public static function finishRun(int $runId, array $output): void
    {
        if ($runId <= 0) {
            return;
        }
        Db::name('aigc_canvas_agent_run')->where('id', $runId)->update([
            'status' => 'success',
            'output_json' => json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'update_time' => time(),
        ]);
    }

    public static function failRun(int $runId, string $error): void
    {
        if ($runId <= 0) {
            return;
        }
        Db::name('aigc_canvas_agent_run')->where('id', $runId)->update([
            'status' => 'failed',
            'error' => mb_substr($error, 0, 2000, 'UTF-8'),
            'update_time' => time(),
        ]);
    }

    public static function step(int $tenantId, int $runId, string $agentCode, string $stepType, array $input, array $output, string $status = 'success'): void
    {
        Db::name('aigc_canvas_agent_step')->insert([
            'tenant_id' => $tenantId,
            'run_id' => $runId,
            'agent_code' => $agentCode,
            'step_type' => $stepType,
            'input_json' => json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'output_json' => json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => $status,
            'create_time' => time(),
        ]);
    }

    public static function ensureSchema(): void
    {
        // Schema ownership belongs to app migrations.
    }
}
