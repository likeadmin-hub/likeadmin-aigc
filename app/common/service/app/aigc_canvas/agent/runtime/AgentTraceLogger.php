<?php

namespace app\common\service\app\aigc_canvas\agent\runtime;

use think\facade\Db;

final class AgentTraceLogger
{
    public static function startRun(int $tenantId, int $userId, int $projectId, int $threadId, string $agentCode, array $input, string $requestId = '', int $parentRunId = 0, string $subAgentCode = '', int $depth = 0, int $sequence = 0, array $handoff = []): int
    {
        $requestId = mb_substr(trim($requestId), 0, 96, 'UTF-8');
        if ($requestId !== '') {
            $where = [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'request_id' => $requestId,
                'delete_time' => 0,
            ];
            // Root runs are idempotent per user request. Child runs are
            // idempotent only inside their parent so every sub-Agent stays visible.
            if ($parentRunId > 0) {
                $where['parent_run_id'] = $parentRunId;
            }
            $existing = Db::name('aigc_canvas_agent_run')->where($where)->find();
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
            'parent_run_id' => $parentRunId,
            'sub_agent_code' => $subAgentCode,
            'depth' => $depth,
            'sequence' => $sequence,
            'handoff_json' => json_encode($handoff, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
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

    public static function queueRun(int $runId, array $handoff = []): void
    {
        if ($runId <= 0) {
            return;
        }
        Db::name('aigc_canvas_agent_run')->where('id', $runId)->update([
            'status' => 'pending',
            'handoff_json' => json_encode($handoff, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'update_time' => time(),
        ]);
    }

    public static function markRunning(int $runId): void
    {
        if ($runId > 0) {
            Db::name('aigc_canvas_agent_run')->where('id', $runId)->update([
                'status' => 'running',
                'update_time' => time(),
            ]);
        }
    }

    public static function setHandoff(int $runId, array $handoff): void
    {
        if ($runId > 0) {
            Db::name('aigc_canvas_agent_run')->where('id', $runId)->update([
                'handoff_json' => json_encode($handoff, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'update_time' => time(),
            ]);
        }
    }

    public static function cancelRun(int $runId, array $output = []): void
    {
        if ($runId <= 0) {
            return;
        }
        Db::name('aigc_canvas_agent_run')->where('id', $runId)->update([
            'status' => 'canceled',
            'output_json' => json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
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
