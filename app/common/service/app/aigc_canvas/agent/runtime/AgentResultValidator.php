<?php

namespace app\common\service\app\aigc_canvas\agent\runtime;

/**
 * Validates the delivery contract returned by a tool without interpreting
 * supplier payloads or resubmitting charged work.
 */
final class AgentResultValidator
{
    public static function validate(string $toolCode, array $result): array
    {
        $toolCalls = array_values(array_filter((array)($result['tool_calls'] ?? []), 'is_array'));
        $assets = array_values(array_filter((array)($result['assets'] ?? []), 'is_array'));
        $actions = array_values(array_filter((array)($result['workspace_actions'] ?? []), 'is_array'));
        $status = self::status($toolCalls);
        $taskId = self::taskId($toolCalls);
        $issues = [];

        if (in_array($toolCode, ['generate_image', 'generate_video', 'generate_music'], true)) {
            if ($toolCalls === []) {
                $issues[] = 'missing_generation_task';
            }
            if ($status === 'success' && $assets === []) {
                $issues[] = 'missing_generation_asset';
            }
            if ($status === 'failed') {
                $issues[] = 'generation_failed';
            }
        }

        foreach ($actions as $action) {
            $type = trim((string)($action['action_type'] ?? ''));
            if ($type === '') {
                $issues[] = 'workspace_action_type_missing';
                continue;
            }
            if ($type === 'insert_text') {
                $content = trim((string)($action['input']['content'] ?? $action['input']['text'] ?? ''));
                if ($content === '') {
                    $issues[] = 'text_overlay_content_missing';
                }
            }
        }

        $issues = array_values(array_unique($issues));
        return [
            'valid' => $issues === [],
            'status' => $status,
            'issues' => $issues,
            'asset_count' => count($assets),
            'workspace_action_count' => count($actions),
            'task_id' => $taskId,
            // Never auto-resubmit paid media. A repair may only synchronize
            // an existing task or replay an idempotent canvas delivery.
            'repairable' => in_array('missing_generation_asset', $issues, true) && in_array($status, ['running', 'success'], true),
        ];
    }

    /**
     * A paid media task is terminal for the current Agent turn once the task
     * has been accepted. Sending its compact tool result back to the model
     * only adds latency and can trigger a second, unrelated model request.
     */
    public static function isConfirmedMediaSubmission(string $toolCode, array $validation): bool
    {
        return in_array($toolCode, ['generate_image', 'generate_video', 'generate_music'], true)
            && !empty($validation['valid'])
            && in_array((string)($validation['status'] ?? ''), ['running', 'success'], true)
            && (int)($validation['task_id'] ?? 0) > 0;
    }

    private static function status(array $toolCalls): string
    {
        $statuses = [];
        foreach ($toolCalls as $call) {
            $value = strtolower(trim((string)($call['status'] ?? $call['output']['status'] ?? '')));
            if ($value !== '') {
                $statuses[] = $value;
            }
        }
        if (in_array('failed', $statuses, true)) return 'failed';
        if (in_array('success', $statuses, true)) return 'success';
        if (in_array('running', $statuses, true) || in_array('pending', $statuses, true)) return 'running';
        return $statuses === [] ? 'unknown' : $statuses[0];
    }

    private static function taskId(array $toolCalls): int
    {
        foreach ($toolCalls as $call) {
            $output = is_array($call['output'] ?? null) ? $call['output'] : [];
            $id = (int)($output['task_id'] ?? $output['id'] ?? 0);
            if ($id > 0) return $id;
        }
        return 0;
    }
}
