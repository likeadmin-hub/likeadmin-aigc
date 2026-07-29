<?php

namespace app\common\service\app\aigc_canvas\agent\delivery;

use app\common\service\app\aigc_canvas\AigcCanvasAgentRuntimeService;
use app\common\service\app\aigc_canvas\agent\generation\CanvasGenerationTaskCenterService;
use Exception;

/**
 * Executes one delivery item without making a conversation thread the owner
 * of the generation. Media submission itself remains in the established
 * runtime so billing, PromptSubmissionService and canvas actions stay shared.
 */
final class DeliveryGraphExecutor
{
    private const MEDIA_TOOLS = ['generate_image', 'generate_video', 'generate_music'];
    private const ACTION_TOOLS = ['canvas_mutation', 'selection_action'];

    public static function execute(int $tenantId, int $userId, int $itemId, array $params = [], ?callable $emit = null): array
    {
        $item = DeliveryItemService::find($tenantId, $userId, $itemId);
        if ($item === []) throw new Exception('Delivery item not found');
        if (!in_array((string)$item['tool_code'], array_merge(self::MEDIA_TOOLS, self::ACTION_TOOLS), true)) {
            throw new Exception('This delivery item has no executable tool');
        }
        self::assertDependencies($tenantId, $userId, $item);
        if (PendingActionProtocol::isPending((array)($item['pending_action'] ?? []))) {
            throw new Exception('This delivery item requires its approved action before execution');
        }
        if (in_array((string)$item['status'], ['queued', 'running', 'completed'], true)) {
            return $item;
        }
        if (!in_array((string)$item['status'], ['ready', 'failed'], true)) {
            throw new Exception('Delivery item is not ready to execute');
        }
        $claim = DeliveryItemService::claimExecution($tenantId, $userId, $itemId);
        $item = (array)($claim['item'] ?? []);
        if (empty($claim['claimed'])) return $item;
        $item = DeliveryItemService::transition($tenantId, $userId, $itemId, 'queued', ['pending_action_json' => []]);
        $input = self::input($item, DeliveryItemService::executionOptions($tenantId, $userId, $itemId));
        try {
            $result = AigcCanvasAgentRuntimeService::executeExternalToolWithActions(
                $tenantId, $userId, (int)$item['project_id'], (int)$item['thread_id'],
                (int)$item['source_message_id'], (string)$item['tool_code'], $input,
                (string)$input['prompt'], ['uploaded_references' => (array)$item['reference_assets']], $emit, 1
            );
            $tool = (array)($result['tool_calls'][0] ?? []);
            $output = (array)($tool['output'] ?? []);
            $submittedInput = (array)($output['submitted_input'] ?? $tool['input'] ?? $input);
            $assets = (array)($result['assets'] ?? []);
            $hasAsset = !empty(array_filter($assets, static fn($asset): bool => is_array($asset) && !empty($asset['url'])));
            $toolStatus = strtolower((string)($output['status'] ?? 'running'));
            $status = $hasAsset || in_array($toolStatus, ['success', 'completed'], true) ? 'completed' : 'running';
            $taskId = (string)($output['task_id'] ?? $tool['provider_task_id'] ?? '');
            $providerTaskId = (string)($output['power_task_id'] ?? $output['provider_task_id'] ?? $tool['provider_task_id'] ?? '');
            DeliveryItemTaskSyncService::bind($tenantId, $userId, $itemId, [
                'idempotency_key' => (string)$input['request_id'], 'task_id' => $taskId,
                'provider_task_id' => $providerTaskId, 'status' => $status,
            ]);
            return DeliveryItemService::transition($tenantId, $userId, $itemId, $status, [
                'task_snapshot_json' => [
                    'input' => $submittedInput, 'tool_call_id' => (int)($tool['id'] ?? 0),
                    'task_id' => $taskId, 'provider_task_id' => $providerTaskId, 'idempotency_key' => (string)$input['request_id'],
                    'prompt_hash' => (string)($submittedInput['prompt_hash'] ?? hash('sha256', (string)($submittedInput['compiled_prompt'] ?? $submittedInput['prompt'] ?? ''))),
                    'tool_code' => (string)$item['tool_code'], 'workspace_actions' => (array)($result['workspace_actions'] ?? []),
                ],
                'result_json' => ['assets' => $assets, 'workspace_actions' => (array)($result['workspace_actions'] ?? [])],
                'provider_request_id' => (string)($output['request_id'] ?? $input['request_id'] ?? ''),
                'provider_error_code' => '', 'provider_error_message' => '', 'error' => '',
            ]);
        } catch (Exception $e) {
            return DeliveryItemService::transition($tenantId, $userId, $itemId, 'failed', [
                'error' => $e->getMessage(), 'provider_error_code' => 'provider_submit',
                'provider_error_message' => $e->getMessage(),
                'pending_action_json' => PendingActionProtocol::normalize([
                    'action_id' => 'resolve_failure:retry_or_cancel', 'type' => 'resolve_failure',
                    'required_input_schema' => ['type' => 'object', 'required' => ['resolution']],
                    'options' => [['value' => 'retry'], ['value' => 'cancel']],
                    'on_success_transition' => 'ready', 'on_reject_transition' => 'canceled',
                ]),
            ]);
        }
    }

    public static function refresh(int $tenantId, int $userId, int $itemId): array
    {
        $item = DeliveryItemService::find($tenantId, $userId, $itemId);
        if ($item === []) throw new Exception('Delivery item not found');
        if (!in_array((string)$item['tool_code'], self::MEDIA_TOOLS, true)) return $item;
        try {
            $synced = DeliveryItemTaskSyncService::refresh($tenantId, $userId, $itemId);
            return $synced !== [] ? $synced : $item;
        } catch (Exception $e) {
            return DeliveryItemService::transition($tenantId, $userId, $itemId, 'failed', [
                'error' => $e->getMessage(), 'provider_error_code' => 'provider_callback',
                'provider_error_message' => $e->getMessage(), 'pending_action_json' => PendingActionProtocol::confirmation('retry_item'),
            ]);
        }
    }

    public static function retry(int $tenantId, int $userId, int $itemId): array
    {
        $item = DeliveryItemService::find($tenantId, $userId, $itemId);
        if ($item === []) throw new Exception('Delivery item not found');
        $snapshot = (array)($item['task_snapshot'] ?? []);
        $taskId = trim((string)($snapshot['task_id'] ?? ''));
        if (!in_array((string)$item['tool_code'], self::MEDIA_TOOLS, true)) return self::execute($tenantId, $userId, $itemId);
        if ($taskId === '') return self::execute($tenantId, $userId, $itemId);
        $result = CanvasGenerationTaskCenterService::retry($tenantId, $userId, [
            'task_id' => $taskId, 'type' => str_replace('generate_', '', (string)$item['tool_code']),
            'submitted_input' => (array)($snapshot['input'] ?? []),
        ]);
        $nextTaskId = (string)($result['task_id'] ?? $taskId);
        $idempotencyKey = 'delivery_item:' . $itemId . ':' . ((int)$item['retry_count'] + 2);
        DeliveryItemTaskSyncService::bind($tenantId, $userId, $itemId, [
            'idempotency_key' => $idempotencyKey, 'task_id' => $nextTaskId,
            'provider_task_id' => (string)($result['power_task_id'] ?? $nextTaskId), 'status' => 'queued',
        ]);
        return DeliveryItemService::transition($tenantId, $userId, $itemId, 'queued', [
            'retry_count' => (int)$item['retry_count'] + 1, 'pending_action_json' => [],
            'task_snapshot_json' => array_merge($snapshot, ['task_id' => $nextTaskId, 'idempotency_key' => $idempotencyKey, 'retry_of' => $taskId]),
        ]);
    }

    public static function cancel(int $tenantId, int $userId, int $itemId): array
    {
        $item = DeliveryItemService::find($tenantId, $userId, $itemId);
        if ($item === []) throw new Exception('Delivery item not found');
        $taskId = trim((string)($item['task_snapshot']['task_id'] ?? ''));
        if (in_array((string)$item['tool_code'], self::MEDIA_TOOLS, true) && $taskId !== '' && in_array((string)$item['status'], ['queued', 'running'], true)) {
            CanvasGenerationTaskCenterService::cancel($tenantId, $userId, [
                'task_id' => $taskId, 'type' => str_replace('generate_', '', (string)$item['tool_code']),
            ]);
        }
        $binding = DeliveryItemTaskSyncService::latest($tenantId, $userId, $itemId);
        if ($binding !== []) {
            DeliveryItemTaskSyncService::bind($tenantId, $userId, $itemId, [
                'idempotency_key' => (string)$binding['idempotency_key'], 'task_id' => (string)$binding['generation_task_id'],
                'provider_task_id' => (string)$binding['provider_task_id'], 'status' => 'canceled',
            ]);
        }
        return DeliveryItemService::transition($tenantId, $userId, $itemId, 'canceled', ['pending_action_json' => []]);
    }

    private static function assertDependencies(int $tenantId, int $userId, array $item): void
    {
        foreach ((array)($item['depends_on'] ?? []) as $dependencyId) {
            $dependency = DeliveryItemService::find($tenantId, $userId, (int)$dependencyId);
            if ($dependency === [] || (string)$dependency['status'] !== 'completed') {
                throw new Exception('A prerequisite delivery item has not completed');
            }
        }
    }

    private static function input(array $item, array $executionOptions): array
    {
        $delivery = (array)($item['delivery'] ?? []);
        $slots = (array)($item['slots'] ?? []);
        $ratio = trim((string)($delivery['ratio'] ?? ''));
        return array_merge(self::executionOptions($executionOptions), [
            '__delivery_graph_execution' => true,
            'prompt' => (string)($slots['user_request'] ?? $delivery['user_request'] ?? $item['objective']),
            'user_request' => (string)($slots['user_request'] ?? ''), 'prompt_mode' => self::promptMode($item),
            'delivery' => $delivery, 'creative_context' => (array)($item['creative_context'] ?? []),
            'project_id' => (int)$item['project_id'], 'ratio' => $ratio, 'quantity' => 1,
            'target_element_id' => (string)($delivery['target_element_id'] ?? 'delivery_item_' . (int)$item['id']),
            'request_id' => 'delivery_item:' . (int)$item['id'] . ':' . ((int)$item['retry_count'] + 1),
            'reference_assets' => (array)$item['reference_assets'],
            'reference_images' => array_values(array_filter(array_map(static fn(array $asset): string => (string)($asset['url'] ?? $asset['uri'] ?? ''), array_filter((array)$item['reference_assets'], 'is_array')))),
        ]);
    }

    /** Only policy-approved server routing values may survive into submission. */
    private static function executionOptions(array $options): array
    {
        return array_intersect_key($options, array_flip([
            'channel', 'model_id', 'market_product_id', 'market_sku_id', 'sku_id',
            'duration', 'quality', 'style', 'seed', 'negative_prompt',
        ]));
    }

    /**
     * A lightweight creative request should be enhanced as direct creation.
     * Product evidence, references and ecommerce deliveries retain the planned
     * path so their evidence and claim constraints remain traceable.
     */
    private static function promptMode(array $item): string
    {
        $skillKey = (string)($item['skill_key'] ?? '');
        $delivery = (array)($item['delivery'] ?? []);
        $context = (array)($item['creative_context'] ?? []);
        if (str_starts_with($skillKey, 'ecommerce_')
            || str_contains((string)($delivery['type'] ?? ''), 'ecommerce')
            || !empty($item['reference_assets'])
            || !empty($context['product_identity'])
            || !empty($context['evidence_catalog'])
            || !empty($context['claim_policy'])) {
            return 'planned';
        }
        return 'direct';
    }
}
