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
    private static $submissionAdapter = null;

    /**
     * Isolated integration tests inject a deterministic channel response here.
     * Production continues through the established runtime submission path.
     */
    public static function setSubmissionAdapterForTesting(?callable $adapter): void
    {
        self::$submissionAdapter = $adapter;
    }

    public static function execute(int $tenantId, int $userId, int $itemId, array $params = [], ?callable $emit = null): array
    {
        $item = DeliveryItemService::find($tenantId, $userId, $itemId);
        if ($item === []) throw new Exception('Delivery item not found');
        if (!in_array((string)$item['tool_code'], array_merge(self::MEDIA_TOOLS, self::ACTION_TOOLS), true)) {
            throw new Exception('This delivery item has no executable tool');
        }
        self::assertDependencies($tenantId, $userId, $item);
        $item = DeliveryItemContextBinder::bindBase($tenantId, $userId, $item, [
            'content' => (string)($item['slots']['user_request'] ?? ''),
        ]);
        DeliveryItemContextBinder::ensureExecutableContext($tenantId, $userId, $item);
        if ((string)$item['status'] !== 'ready') {
            throw new Exception('Delivery item is not ready to execute');
        }
        $item = DeliveryItemService::claimReady($tenantId, $userId, $itemId, ['pending_action_json' => []]);
        $input = self::input($item, $params);
        try {
            // Claiming is committed above. Provider submission never occurs in
            // the compare-and-swap update that protects duplicate confirms.
            $item = DeliveryItemService::transition($tenantId, $userId, $itemId, 'running');
            $result = self::submit(
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
                'task_snapshot_json' => self::snapshot($item, $tool, $output, $input, $result),
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

    /**
     * AgentLoop uses this path for media tools that already have an item. The
     * graph owns claim/state/snapshot while Runtime keeps provider submission,
     * prompt compilation, billing and workspace actions unchanged.
     */
    public static function executeFromAgentTool(
        int $tenantId,
        int $userId,
        int $projectId,
        int $threadId,
        int $messageId,
        int $itemId,
        string $toolCode,
        array $input,
        string $prompt,
        array $context,
        ?callable $emit = null
    ): array {
        $item = DeliveryItemService::find($tenantId, $userId, $itemId);
        if ($item === []) throw new Exception('Delivery item not found');
        if (!in_array($toolCode, self::MEDIA_TOOLS, true) || (string)$item['tool_code'] !== $toolCode) {
            throw new Exception('Delivery item tool does not match Agent media tool');
        }
        $item = DeliveryItemContextBinder::bindBase($tenantId, $userId, $item, $context + [
            'content' => (string)($input['user_request'] ?? $input['prompt'] ?? ''),
        ]);
        DeliveryItemContextBinder::ensureExecutableContext($tenantId, $userId, $item);
        $item = DeliveryItemService::claimReady($tenantId, $userId, $itemId, ['pending_action_json' => []]);
        $input = array_merge($input, [
            'delivery_item_id' => $itemId,
            'attempt_no' => (int)$item['retry_count'] + 1,
            'reference_assets' => (array)$item['reference_assets'],
        ]);
        try {
            DeliveryItemService::transition($tenantId, $userId, $itemId, 'running');
            $result = self::submit(
                $tenantId,
                $userId,
                $projectId,
                $threadId,
                $messageId,
                $toolCode,
                $input,
                $prompt,
                $context,
                $emit
            );
            $tool = (array)($result['tool_calls'][0] ?? []);
            $output = (array)($tool['output'] ?? []);
            $assets = (array)($result['assets'] ?? []);
            $completed = !empty(array_filter($assets, static fn($asset): bool => is_array($asset) && !empty($asset['url'])))
                || in_array(strtolower((string)($output['status'] ?? 'running')), ['success', 'completed'], true);
            DeliveryItemService::transition($tenantId, $userId, $itemId, $completed ? 'completed' : 'running', [
                'task_snapshot_json' => self::snapshot($item, $tool, $output, $input, $result),
                'result_json' => ['assets' => $assets, 'workspace_actions' => (array)($result['workspace_actions'] ?? [])],
                'provider_request_id' => (string)($output['request_id'] ?? $input['request_id'] ?? ''),
                'provider_error_code' => '',
                'provider_error_message' => '',
                'error' => '',
            ]);
            return $result;
        } catch (Exception $e) {
            DeliveryItemService::transition($tenantId, $userId, $itemId, 'failed', [
                'error' => $e->getMessage(),
                'provider_error_code' => 'provider_submit',
                'provider_error_message' => $e->getMessage(),
                'pending_action_json' => PendingActionProtocol::confirmation('retry_item'),
            ]);
            throw $e;
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
                'task_id' => $taskId, 'type' => str_replace('generate_', '', (string)$item['tool_code']), 'delivery_item_id' => $itemId,
            ]);
            return DeliveryItemTaskSyncService::syncGenerationTask($tenantId, $userId, $detail, $itemId);
        } catch (Exception $e) {
            return DeliveryItemService::transition($tenantId, $userId, $itemId, 'failed', [
                'error' => $e->getMessage(), 'provider_error_code' => 'provider_callback',
                'provider_error_message' => $e->getMessage(), 'pending_action_json' => PendingActionProtocol::confirmation('retry_item'),
            ]);
        }
    }

    public static function retry(int $tenantId, int $userId, int $itemId): array
    {
        $item = DeliveryItemService::beginRetry($tenantId, $userId, $itemId);
        $snapshot = (array)($item['task_snapshot'] ?? []);
        return self::execute($tenantId, $userId, $itemId, [
            'retry_of_task_id' => (string)($snapshot['task_id'] ?? ''),
            'submitted_input' => (array)($snapshot['submitted_input'] ?? $snapshot['input'] ?? []),
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

    private static function submit(
        int $tenantId,
        int $userId,
        int $projectId,
        int $threadId,
        int $messageId,
        string $toolCode,
        array $input,
        string $prompt,
        array $context,
        ?callable $emit,
        int $maxActions = 0
    ): array {
        if (self::$submissionAdapter !== null) {
            return (self::$submissionAdapter)([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'project_id' => $projectId,
                'thread_id' => $threadId,
                'message_id' => $messageId,
                'tool_code' => $toolCode,
                'input' => $input,
                'prompt' => $prompt,
                'context' => $context,
                'emit' => $emit,
                'max_actions' => $maxActions,
            ]);
        }
        return AigcCanvasAgentRuntimeService::executeExternalToolWithActions(
            $tenantId, $userId, $projectId, $threadId, $messageId, $toolCode,
            $input, $prompt, $context, $emit, $maxActions
        );
    }

    private static function snapshot(array $item, array $tool, array $output, array $input, array $result): array
    {
        $previous = (array)($item['task_snapshot'] ?? []);
        $attempt = max(1, (int)($item['retry_count'] ?? 0) + 1);
        $entry = [
            'tool_call_id' => (int)($tool['id'] ?? 0),
            'task_id' => (string)($tool['provider_task_id'] ?? $output['task_id'] ?? ''),
            'provider_task_id' => (string)($tool['provider_task_id'] ?? $output['task_id'] ?? ''),
            'request_id' => (string)($output['request_id'] ?? $input['request_id'] ?? ''),
            'attempt_no' => $attempt,
            'submitted_input' => (array)($tool['input'] ?? $input),
            'prompt_hash' => hash('sha256', (string)($tool['input']['prompt'] ?? $input['prompt'] ?? '')),
            'tool_code' => (string)($item['tool_code'] ?? ''),
            'workspace_actions' => (array)($result['workspace_actions'] ?? []),
        ];
        $attempts = array_values((array)($previous['attempts'] ?? []));
        if ($attempts === [] && !empty($previous['attempt_no'])) {
            $attempts[] = $previous;
        }
        foreach ($attempts as $index => $candidate) {
            if ((int)($candidate['attempt_no'] ?? 0) === $attempt) {
                $attempts[$index] = $entry;
                return $entry + ['attempts' => $attempts];
            }
        }
        $attempts[] = $entry;
        return $entry + ['attempts' => $attempts];
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
            'delivery_item_id' => (int)$item['id'],
            'attempt_no' => (int)$item['retry_count'] + 1,
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
