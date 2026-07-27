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
        if (!in_array((string)$item['status'], ['ready', 'queued', 'failed'], true)) {
            throw new Exception('Delivery item is not ready to execute');
        }
        $item = DeliveryItemService::transition($tenantId, $userId, $itemId, 'queued', ['pending_action_json' => []]);
        $input = self::input($item, $params);
        try {
            $result = AigcCanvasAgentRuntimeService::executeExternalToolWithActions(
                $tenantId, $userId, (int)$item['project_id'], (int)$item['thread_id'],
                (int)$item['source_message_id'], (string)$item['tool_code'], $input,
                (string)$input['prompt'], ['uploaded_references' => (array)$item['reference_assets']], $emit, 1
            );
            $tool = (array)($result['tool_calls'][0] ?? []);
            $output = (array)($tool['output'] ?? []);
            $assets = (array)($result['assets'] ?? []);
            $hasAsset = !empty(array_filter($assets, static fn($asset): bool => is_array($asset) && !empty($asset['url'])));
            $toolStatus = strtolower((string)($output['status'] ?? 'running'));
            $status = $hasAsset || in_array($toolStatus, ['success', 'completed'], true) ? 'completed' : 'running';
            return DeliveryItemService::transition($tenantId, $userId, $itemId, $status, [
                'task_snapshot_json' => [
                    'input' => (array)($tool['input'] ?? $input), 'tool_call_id' => (int)($tool['id'] ?? 0),
                    'task_id' => (string)($tool['provider_task_id'] ?? $output['task_id'] ?? ''),
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
        $snapshot = (array)($item['task_snapshot'] ?? []);
        $taskId = trim((string)($snapshot['task_id'] ?? ''));
        if (!in_array((string)$item['tool_code'], self::MEDIA_TOOLS, true)) return $item;
        if ($taskId === '') return $item;
        try {
            $detail = CanvasGenerationTaskCenterService::query($tenantId, $userId, [
                'task_id' => $taskId, 'type' => str_replace('generate_', '', (string)$item['tool_code']),
            ]);
            $providerStatus = strtolower((string)($detail['status'] ?? 'running'));
            $status = in_array($providerStatus, ['success', 'completed'], true) ? 'completed'
                : (in_array($providerStatus, ['failed', 'error'], true) ? 'failed'
                    : (in_array($providerStatus, ['canceled', 'cancelled'], true) ? 'canceled' : 'running'));
            $patch = [
                'result_json' => ['assets' => (array)($detail['result_assets'] ?? []), 'generation' => $detail],
                'provider_request_id' => (string)($detail['request_id'] ?? ''),
                'provider_error_code' => (string)($detail['error_code'] ?? ''),
                'provider_error_message' => (string)($detail['error_message'] ?? ''),
                'error' => (string)($detail['error_message'] ?? ''),
            ];
            if ($status === 'failed') $patch['pending_action_json'] = PendingActionProtocol::confirmation('retry_item');
            return DeliveryItemService::transition($tenantId, $userId, $itemId, $status, $patch);
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
        return DeliveryItemService::transition($tenantId, $userId, $itemId, 'queued', [
            'retry_count' => (int)$item['retry_count'] + 1, 'pending_action_json' => [],
            'task_snapshot_json' => array_merge($snapshot, ['task_id' => (string)($result['task_id'] ?? $taskId), 'retry_of' => $taskId]),
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

    private static function input(array $item, array $params): array
    {
        $delivery = (array)($item['delivery'] ?? []);
        $slots = (array)($item['slots'] ?? []);
        $ratio = trim((string)($params['ratio'] ?? $delivery['ratio'] ?? ''));
        return array_merge($params, [
            'prompt' => (string)($slots['user_request'] ?? $delivery['user_request'] ?? $item['objective']),
            'user_request' => (string)($slots['user_request'] ?? ''), 'prompt_mode' => self::promptMode($item),
            'delivery' => $delivery, 'creative_context' => (array)($item['creative_context'] ?? []),
            'project_id' => (int)$item['project_id'], 'ratio' => $ratio, 'quantity' => 1,
            'target_element_id' => 'delivery_item_' . (int)$item['id'],
            'request_id' => 'delivery_item:' . (int)$item['id'] . ':' . ((int)$item['retry_count'] + 1),
            'reference_assets' => (array)$item['reference_assets'],
            'reference_images' => array_values(array_filter(array_map(static fn(array $asset): string => (string)($asset['url'] ?? $asset['uri'] ?? ''), array_filter((array)$item['reference_assets'], 'is_array')))),
        ]);
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
