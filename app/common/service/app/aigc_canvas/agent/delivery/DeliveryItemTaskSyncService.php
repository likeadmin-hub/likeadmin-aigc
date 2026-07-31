<?php

namespace app\common\service\app\aigc_canvas\agent\delivery;

use app\common\model\app\aigc_canvas\AigcCanvasAgentToolCall;
use Exception;

/** Shared projection from durable generation records onto a delivery item. */
final class DeliveryItemTaskSyncService
{
    /**
     * Persists a completed text/research workflow stage from the Agent loop.
     * It never invents a provider task, source, asset, price, or tool result.
     */
    public static function syncTextStage(int $tenantId, int $userId, int $itemId, array $result): array
    {
        $item = DeliveryItemService::find($tenantId, $userId, $itemId);
        if ($item === [] || in_array((string)$item['status'], ['completed', 'canceled'], true)) return $item;
        $meta = (array)($item['meta'] ?? []);
        if (empty($meta['workflow_stage']) || (string)$item['tool_code'] !== 'generate_text') return $item;
        foreach ((array)($item['depends_on'] ?? []) as $dependencyId) {
            $dependency = DeliveryItemService::find($tenantId, $userId, (int)$dependencyId);
            if ($dependency === [] || (string)$dependency['status'] !== 'completed') return $item;
        }
        if ((string)$item['status'] === 'draft') {
            $item = DeliveryItemService::transition($tenantId, $userId, $itemId, 'ready');
        }
        if ((string)$item['status'] === 'ready') {
            $item = DeliveryItemService::claimReady($tenantId, $userId, $itemId, ['pending_action_json' => []]);
        }
        if ((string)$item['status'] === 'queued') {
            $item = DeliveryItemService::transition($tenantId, $userId, $itemId, 'running');
        }
        if ((string)$item['status'] !== 'running') return $item;
        $reply = trim((string)($result['reply'] ?? ''));
        if ($reply === '') return $item;
        $toolCalls = array_values(array_filter((array)($result['tool_calls'] ?? []), 'is_array'));
        $research = self::researchProjection($toolCalls, $reply);
        $snapshot = self::textSnapshot($item, $toolCalls);
        $item = DeliveryItemService::transition($tenantId, $userId, $itemId, 'completed', [
            'result_json' => [
                'text' => $reply,
                'research' => $research,
                'tool_calls' => $toolCalls,
            ],
            'task_snapshot_json' => $snapshot,
            'error' => '',
            'provider_error_code' => '',
            'provider_error_message' => '',
            'pending_action_json' => [],
        ]);
        DeliveryPlanService::advanceDependencies($tenantId, $userId, (int)$item['plan_id']);
        return $item;
    }

    public static function syncGenerationTask(int $tenantId, int $userId, array $task, int $itemId = 0): array
    {
        $providerTaskId = trim((string)($task['power_task_id'] ?? $task['provider_task_id'] ?? $task['task_id'] ?? ''));
        $toolCall = self::toolCall($tenantId, $userId, $providerTaskId, (int)($task['tool_call_id'] ?? 0));
        $itemId = $itemId > 0 ? $itemId : (int)($toolCall['delivery_item_id'] ?? 0);
        if ($itemId <= 0) return [];
        $item = DeliveryItemService::find($tenantId, $userId, $itemId);
        if ($item === [] || in_array((string)$item['status'], ['completed', 'canceled'], true)) return $item;

        $status = self::status((string)($task['status'] ?? 'running'));
        $previousResult = (array)($item['result'] ?? []);
        $patch = [
            'task_snapshot_json' => self::snapshot($item, $task, $toolCall),
            'result_json' => [
                'assets' => array_values((array)($task['result_assets'] ?? $task['assets'] ?? [])),
                'generation' => $task,
                'workspace_actions' => array_values((array)($task['workspace_actions'] ?? $previousResult['workspace_actions'] ?? [])),
            ],
            'provider_request_id' => (string)($task['request_id'] ?? ''),
            'provider_error_code' => (string)($task['error_code'] ?? ''),
            'provider_error_message' => (string)($task['error_message'] ?? ''),
            'error' => (string)($task['error_message'] ?? ''),
        ];
        if ($status === 'failed') $patch['pending_action_json'] = PendingActionProtocol::confirmation('retry_item');
        try {
            if ((string)$item['status'] === 'ready') {
                if ($status === 'canceled') {
                    return DeliveryItemService::transition($tenantId, $userId, $itemId, 'canceled', $patch);
                }
                $item = DeliveryItemService::claimReady($tenantId, $userId, $itemId, ['pending_action_json' => []]);
            }
            if ((string)$item['status'] === 'queued') {
                if (in_array($status, ['failed', 'canceled'], true)) {
                    return DeliveryItemService::transition($tenantId, $userId, $itemId, $status, $patch);
                }
                $item = DeliveryItemService::transition($tenantId, $userId, $itemId, 'running');
            }
            if ((string)$item['status'] === 'running' || (string)$item['status'] === $status) {
                $item = DeliveryItemService::transition($tenantId, $userId, $itemId, $status, $patch);
            }
        } catch (Exception) {
            return DeliveryItemService::find($tenantId, $userId, $itemId);
        }
        if ((string)($item['status'] ?? '') === 'completed') {
            DeliveryPlanService::advanceDependencies($tenantId, $userId, (int)$item['plan_id']);
        }
        return $item;
    }

    public static function syncAgentToolCall(int $tenantId, int $userId, int $toolCallId, array $task): array
    {
        $toolCall = self::toolCall($tenantId, $userId, '', $toolCallId);
        if ($toolCall === []) return [];
        $status = self::status((string)($task['status'] ?? 'running'));
        AigcCanvasAgentToolCall::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'id' => (int)$toolCall['id'],
            'delete_time' => 0,
        ])->update([
            'status' => $status === 'completed' ? 'success' : ($status === 'failed' ? 'failed' : 'running'),
            'provider_task_id' => (string)($task['power_task_id'] ?? $task['provider_task_id'] ?? $task['task_id'] ?? $toolCall['provider_task_id'] ?? ''),
            'error' => (string)($task['error_message'] ?? ''),
            'error_code' => (string)($task['error_code'] ?? ''),
            'finished_at' => in_array($status, ['completed', 'failed', 'canceled'], true) ? time() : 0,
            'update_time' => time(),
        ]);
        return self::syncGenerationTask($tenantId, $userId, $task, (int)($toolCall['delivery_item_id'] ?? 0));
    }

    private static function toolCall(int $tenantId, int $userId, string $providerTaskId, int $toolCallId): array
    {
        $query = AigcCanvasAgentToolCall::where(['tenant_id' => $tenantId, 'user_id' => $userId, 'delete_time' => 0]);
        if ($toolCallId > 0) $query->where('id', $toolCallId);
        elseif ($providerTaskId !== '') $query->where('provider_task_id', $providerTaskId);
        else return [];
        $row = $query->order('id', 'desc')->findOrEmpty();
        return $row->isEmpty() ? [] : $row->toArray();
    }

    private static function status(string $status): string
    {
        $status = strtolower($status);
        if (in_array($status, ['success', 'completed'], true)) return 'completed';
        if (in_array($status, ['failed', 'error', 'fail'], true)) return 'failed';
        if (in_array($status, ['canceled', 'cancelled'], true)) return 'canceled';
        return 'running';
    }

    private static function snapshot(array $item, array $task, array $toolCall): array
    {
        $previous = (array)($item['task_snapshot'] ?? []);
        $attempt = max(1, (int)($toolCall['attempt_no'] ?? $previous['attempt_no'] ?? ((int)$item['retry_count'] + 1)));
        $entry = [
            'tool_call_id' => (int)($toolCall['id'] ?? $previous['tool_call_id'] ?? 0),
            'provider_task_id' => (string)($task['power_task_id'] ?? $task['provider_task_id'] ?? $task['task_id'] ?? $previous['provider_task_id'] ?? ''),
            'request_id' => (string)($task['request_id'] ?? $previous['request_id'] ?? ''),
            'attempt_no' => $attempt,
            'submitted_input' => (array)($task['submitted_input'] ?? $previous['submitted_input'] ?? $toolCall['input_json'] ?? []),
            'prompt_hash' => (string)($task['prompt_hash'] ?? $previous['prompt_hash'] ?? ''),
            'workspace_actions' => (array)($task['workspace_actions'] ?? $previous['workspace_actions'] ?? []),
        ];
        $attempts = array_values((array)($previous['attempts'] ?? []));
        foreach ($attempts as $index => $candidate) {
            if ((int)($candidate['attempt_no'] ?? 0) === $attempt) {
                $attempts[$index] = $entry;
                return $entry + ['attempts' => $attempts];
            }
        }
        $attempts[] = $entry;
        return $entry + ['attempts' => $attempts];
    }

    private static function textSnapshot(array $item, array $toolCalls): array
    {
        $previous = (array)($item['task_snapshot'] ?? []);
        $attempt = max(1, (int)($item['retry_count'] ?? 0) + 1);
        $entry = [
            'attempt_no' => $attempt,
            'execution' => 'agent_loop',
            'completed_at' => time(),
            'tool_calls' => $toolCalls,
        ];
        $attempts = array_values((array)($previous['attempts'] ?? []));
        foreach ($attempts as $index => $candidate) {
            if ((int)($candidate['attempt_no'] ?? 0) === $attempt) {
                $attempts[$index] = $entry;
                return $entry + ['attempts' => $attempts];
            }
        }
        $attempts[] = $entry;
        return $entry + ['attempts' => $attempts];
    }

    private static function researchProjection(array $toolCalls, string $reply): array
    {
        $sources = [];
        foreach ($toolCalls as $toolCall) {
            $toolCode = (string)($toolCall['tool_code'] ?? $toolCall['code'] ?? $toolCall['name'] ?? '');
            if (!in_array($toolCode, ['web_fetch', 'search_web_info', 'brand_research'], true)) continue;
            $output = (array)($toolCall['output'] ?? []);
            foreach ((array)($output['sources'] ?? $output['links'] ?? []) as $source) {
                if (!is_array($source)) continue;
                $url = trim((string)($source['url'] ?? $source['link'] ?? ''));
                if ($url === '') continue;
                $sources[] = ['title' => trim((string)($source['title'] ?? $source['name'] ?? $url)), 'url' => $url];
            }
        }
        return $sources === []
            ? ['basis' => 'known_information', 'summary' => $reply]
            : ['basis' => 'tool_results', 'summary' => $reply, 'sources' => array_values($sources)];
    }
}
