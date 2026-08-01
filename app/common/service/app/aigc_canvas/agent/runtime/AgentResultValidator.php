<?php

namespace app\common\service\app\aigc_canvas\agent\runtime;

/**
 * Validates the delivery contract returned by a tool without interpreting
 * supplier payloads or resubmitting charged work.
 */
final class AgentResultValidator
{
    private const MEDIA_TOOLS = ['generate_image', 'generate_video', 'generate_music'];

    public static function validate(string $toolCode, array $result): array
    {
        $toolCalls = array_values(array_filter((array)($result['tool_calls'] ?? []), 'is_array'));
        $assets = array_values(array_filter((array)($result['assets'] ?? []), 'is_array'));
        $actions = array_values(array_filter((array)($result['workspace_actions'] ?? []), 'is_array'));
        $status = self::status($toolCode, $toolCalls);
        $taskId = self::taskId($toolCode, $toolCalls);
        $issues = [];
        $confirmedActions = 0;
        $pendingActions = 0;
        $failedActions = 0;

        if (self::isMediaTool($toolCode)) {
            if (self::callsForTool($toolCode, $toolCalls) === []) {
                $issues[] = 'missing_generation_task';
            }
            if ($taskId <= 0) {
                $issues[] = 'missing_generation_task_id';
            }
            if ($status === 'failed') {
                $issues[] = 'generation_failed';
            } elseif (!in_array($status, ['running', 'success'], true)) {
                $issues[] = 'generation_not_accepted';
            }
            if ($assets === []) {
                $issues[] = 'missing_generation_asset';
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
            $actionStatus = self::actionStatus($action);
            if (in_array($actionStatus, ['failed', 'rejected', 'canceled'], true)) {
                $failedActions++;
                $issues[] = 'workspace_action_failed';
            } elseif (in_array($actionStatus, ['applied', 'success', 'completed'], true)) {
                $confirmedActions++;
            } else {
                $pendingActions++;
            }
        }

        if (self::isMediaTool($toolCode) && !self::hasExpectedMediaAction($toolCode, $actions)) {
            $issues[] = 'missing_generation_workspace_action';
        }
        if ($toolCode === 'generate_text' && !self::hasTextResult($toolCalls, $actions)) {
            $issues[] = 'missing_text_result';
        }
        if (!self::isMediaTool($toolCode) && $toolCode !== 'generate_text'
            && $toolCalls === [] && $actions === []) {
            $issues[] = 'missing_tool_result';
        }

        $issues = array_values(array_unique($issues));
        return [
            'valid' => $issues === [],
            'status' => $status,
            'issues' => $issues,
            'asset_count' => count($assets),
            'workspace_action_count' => count($actions),
            'confirmed_workspace_action_count' => $confirmedActions,
            'pending_workspace_action_count' => $pendingActions,
            'failed_workspace_action_count' => $failedActions,
            'task_id' => $taskId,
            'completion_state' => self::completionState($toolCode, $status, $issues, $confirmedActions, $pendingActions),
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
        return self::isMediaTool($toolCode)
            && !empty($validation['valid'])
            && in_array((string)($validation['status'] ?? ''), ['running', 'success'], true)
            && (int)($validation['task_id'] ?? 0) > 0;
    }

    public static function isConfirmedWorkspaceAction(array $validation): bool
    {
        return !empty($validation['valid'])
            && (int)($validation['confirmed_workspace_action_count'] ?? 0) > 0;
    }

    private static function isMediaTool(string $toolCode): bool
    {
        return in_array($toolCode, self::MEDIA_TOOLS, true);
    }

    private static function status(string $toolCode, array $toolCalls): string
    {
        $toolCalls = self::callsForTool($toolCode, $toolCalls);
        $statuses = [];
        foreach ($toolCalls as $call) {
            $output = is_array($call['output'] ?? null) ? $call['output'] : [];
            // The persisted tool call can be successful while the accepted
            // provider task later reports failed. Media delivery must follow
            // the task status, not the wrapper call status.
            $value = self::isMediaTool($toolCode)
                ? strtolower(trim((string)($output['status'] ?? $call['status'] ?? '')))
                : strtolower(trim((string)($call['status'] ?? $output['status'] ?? '')));
            if ($value !== '') {
                $statuses[] = $value;
            }
        }
        if (in_array('failed', $statuses, true)) return 'failed';
        if (in_array('success', $statuses, true)) return 'success';
        if (in_array('running', $statuses, true) || in_array('pending', $statuses, true)) return 'running';
        return $statuses === [] ? 'unknown' : $statuses[0];
    }

    private static function taskId(string $toolCode, array $toolCalls): int
    {
        foreach (self::callsForTool($toolCode, $toolCalls) as $call) {
            $output = is_array($call['output'] ?? null) ? $call['output'] : [];
            $id = (int)($output['task_id'] ?? $output['consumption_id'] ?? $output['id'] ?? 0);
            if ($id > 0) return $id;
        }
        return 0;
    }

    private static function callsForTool(string $toolCode, array $toolCalls): array
    {
        return array_values(array_filter($toolCalls, static function (array $call) use ($toolCode): bool {
            $code = trim((string)($call['tool_code'] ?? $call['code'] ?? $call['name'] ?? ''));
            return $code === $toolCode;
        }));
    }

    private static function actionStatus(array $action): string
    {
        return strtolower(trim((string)($action['status'] ?? 'pending')));
    }

    private static function hasExpectedMediaAction(string $toolCode, array $actions): bool
    {
        $type = match ($toolCode) {
            'generate_video' => 'insert_video',
            'generate_music' => 'insert_audio',
            default => 'insert_image',
        };
        foreach ($actions as $action) {
            if ((string)($action['action_type'] ?? '') !== $type) {
                continue;
            }
            if (!in_array(self::actionStatus($action), ['failed', 'rejected', 'canceled'], true)) {
                return true;
            }
        }
        return false;
    }

    private static function hasTextResult(array $toolCalls, array $actions): bool
    {
        foreach ($toolCalls as $call) {
            $output = is_array($call['output'] ?? null) ? $call['output'] : [];
            foreach (['content', 'text', 'answer', 'reply'] as $key) {
                if (trim((string)($output[$key] ?? '')) !== '') {
                    return true;
                }
            }
        }
        foreach ($actions as $action) {
            if ((string)($action['action_type'] ?? '') !== 'insert_text') {
                continue;
            }
            if (trim((string)($action['input']['content'] ?? $action['input']['text'] ?? '')) !== '') {
                return true;
            }
        }
        return false;
    }

    private static function completionState(string $toolCode, string $status, array $issues, int $confirmedActions, int $pendingActions): string
    {
        if ($issues !== []) {
            return $status === 'failed' ? 'failed' : 'unverified';
        }
        if (self::isMediaTool($toolCode)) {
            return $status === 'success' ? 'completed' : 'submitted';
        }
        if ($pendingActions > 0 && $confirmedActions === 0) {
            return 'pending_confirmation';
        }
        return 'completed';
    }
}
