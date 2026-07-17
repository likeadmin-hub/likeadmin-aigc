<?php

namespace app\common\service\app\aigc_canvas\agent\batch;

use app\common\model\app\aigc_canvas\AigcCanvasAgentBatch;
use app\common\model\app\aigc_canvas\AigcCanvasAgentMessage;
use app\common\model\app\aigc_canvas\AigcCanvasAgentToolCall;
use app\common\model\app\aigc_canvas\AigcCanvasAgentWorkspaceAction;
use app\common\service\app\aigc_canvas\AigcCanvasAgentRuntimeService;
use app\common\service\app\aigc_canvas\AigcCanvasService;
use app\common\service\app\aigc_canvas\agent\planning\EcommerceDetailSectionPlanner;
use app\common\service\app\aigc_canvas\agent\planning\RevisionPlanner;
use Exception;
use think\facade\Db;

final class EcommerceAgentBatchService
{
    private const BATCH_SIZE = 5;
    private const TERMINAL_TASK_STATUSES = ['success', 'failed', 'canceled', 'cancelled'];

    public static function prepare(
        int $tenantId,
        int $userId,
        int $projectId,
        int $threadId,
        int $messageId,
        string $content,
        array $context,
        array $route,
        ?callable $emit = null
    ): array {
        $plan = [
            'section_count' => (int)($route['slots']['section_count'] ?? 0),
            'recommended_section_count' => (int)($route['slots']['recommended_section_count'] ?? 0),
            'count_reason' => (string)($route['slots']['count_reason'] ?? ''),
            'detail_sections' => EcommerceDetailSectionPlanner::normalizeSections((array)($route['slots']['detail_sections'] ?? [])),
            'design_analysis' => is_array($route['slots']['design_analysis'] ?? null) ? $route['slots']['design_analysis'] : [],
            'analysis_source' => (string)($route['slots']['analysis_source'] ?? 'fallback'),
        ];
        $plan['section_count'] = count($plan['detail_sections']);
        if ($plan['section_count'] <= 0) {
            throw new Exception('未能生成有效的详情页图片规划，请补充商品资料后重试');
        }
        $references = self::normalizeReferences(array_merge(
            (array)($route['uploaded_references'] ?? []),
            (array)($context['uploaded_references'] ?? [])
        ));
        $mediaConfig = is_array($route['tool_options']['generate_image'] ?? null)
            ? $route['tool_options']['generate_image']
            : [];
        $requestId = trim((string)($route['request_id'] ?? ''));
        $existing = AigcCanvasAgentBatch::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'request_id' => $requestId,
            'delete_time' => 0,
        ])->findOrEmpty();
        if (!$existing->isEmpty()) {
            $batch = $existing;
        } else {
            $now = time();
            $batch = AigcCanvasAgentBatch::create([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'project_id' => $projectId,
                'thread_id' => $threadId,
                'run_id' => (int)($route['agent_run_id'] ?? 0),
                'analysis_message_id' => $messageId,
                'request_id' => $requestId,
                'skill_key' => 'ecommerce_detail_page',
                'status' => 'awaiting_initial_confirmation',
                'execution_mode' => 'batch',
                'total_count' => $plan['section_count'],
                'next_offset' => 0,
                'batch_size' => self::BATCH_SIZE,
                'current_wave' => 0,
                'notified_wave' => 0,
                'analysis_json' => $plan['design_analysis'],
                'sections_json' => $plan['detail_sections'],
                'references_json' => $references,
                'media_config_json' => $mediaConfig,
                'tasks_json' => [],
                'error' => '',
                'create_time' => $now,
                'update_time' => $now,
                'delete_time' => 0,
            ]);
        }

        $referenceImages = self::referenceImages($references);
        $replyResult = EcommerceDetailSectionPlanner::streamAnalysisReply(
            $tenantId,
            $userId,
            $plan,
            $referenceImages,
            static function (string $delta) use ($emit, $threadId, $messageId): void {
                if (is_callable($emit)) {
                    $emit('agent.message.delta', [
                        'thread_id' => $threadId,
                        'message_id' => $messageId,
                        'delta' => $delta,
                    ]);
                }
            }
        );
        return [
            'reply' => $replyResult['content'],
            'reply_streamed' => !empty($replyResult['streamed']),
            'tool_calls' => [],
            'workspace_actions' => [],
            'assets' => [],
            'next_action' => 'confirm_initial_batch',
            'batch_id' => (int)$batch['id'],
            'design_analysis' => $plan['design_analysis'],
            'planned_sections' => $plan['detail_sections'],
            'total_count' => $plan['section_count'],
            'completed_count' => 0,
            'remaining_count' => $plan['section_count'],
            'batch' => self::formatBatch($batch->toArray()),
        ];
    }

    public static function revise(
        int $tenantId,
        int $userId,
        int $projectId,
        int $threadId,
        int $messageId,
        string $instruction,
        array $decision,
        array $delivery,
        array $route
    ): array {
        $parentBatchId = (int)($decision['target_scope']['batch_id'] ?? $delivery['batch_id'] ?? 0);
        $parent = self::batchQuery($tenantId, $userId, $parentBatchId)->findOrEmpty();
        if ($parent->isEmpty()) {
            throw new Exception('需要修改的原始交付批次不存在');
        }
        $requestId = trim((string)($route['request_id'] ?? ''));
        $existing = AigcCanvasAgentBatch::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'request_id' => $requestId,
            'delete_time' => 0,
        ])->findOrEmpty();
        if (!$existing->isEmpty()) {
            return [
                'reply' => (string)($existing['revision_instruction'] ?? $instruction),
                'tool_calls' => [],
                'workspace_actions' => [],
                'assets' => [],
                'next_action' => 'execute_revision',
                'revision_batch_id' => (int)$existing['id'],
                'revision_of_batch_id' => (int)$existing['parent_batch_id'],
                'batch' => self::formatBatch($existing->toArray()),
            ];
        }

        $revisionDecision = $decision;
        $revisionDelivery = $delivery;
        $propagatesToRemaining = self::shouldPropagateRevisionToRemaining($decision, $delivery, $parent->toArray());
        $remainingSections = $propagatesToRemaining
            ? array_slice((array)$parent['sections_json'], (int)$parent['next_offset'])
            : [];
        if (!empty($remainingSections)) {
            $remainingAssets = array_map(static fn(array $section): array => [
                'section_index' => (int)($section['section_index'] ?? 0),
                'section_key' => (string)($section['section_key'] ?? ''),
                'title' => (string)($section['title'] ?? ''),
                'prompt' => (string)($section['image_prompt'] ?? ''),
                'copy_content' => is_array($section['copy_content'] ?? null) ? $section['copy_content'] : [],
                'url' => '',
                'node_id' => '',
                'target_element_id' => '',
                'status' => 'waiting',
                'ratio' => (string)($section['ratio'] ?? '3:4'),
            ], array_filter($remainingSections, 'is_array'));
            $revisionDelivery['assets'] = array_values(array_merge(
                (array)($delivery['assets'] ?? []),
                $remainingAssets
            ));
            $revisionDecision['target_scope']['section_keys'] = array_values(array_unique(array_merge(
                (array)($decision['target_scope']['section_keys'] ?? []),
                array_column($remainingAssets, 'section_key')
            )));
        }

        $plan = RevisionPlanner::plan($tenantId, $userId, $instruction, $revisionDecision, $revisionDelivery);
        $sections = EcommerceDetailSectionPlanner::normalizeSections((array)($plan['sections'] ?? []));
        // Restore source linkage stripped by the generic section normalizer.
        foreach ($sections as $index => &$section) {
            $source = (array)($plan['sections'][$index] ?? []);
            foreach (['source_node_id', 'source_target_element_id', 'source_asset_url', 'source_section_key'] as $key) {
                $section[$key] = (string)($source[$key] ?? '');
            }
        }
        unset($section);
        if (empty($sections)) {
            throw new Exception('没有生成有效的修订计划');
        }
        $revisionNo = (int)AigcCanvasAgentBatch::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'parent_batch_id' => $parentBatchId,
            'delete_time' => 0,
        ])->max('revision_no') + 1;
        $references = self::normalizeReferences((array)$parent['references_json']);
        foreach ($sections as $section) {
            $url = trim((string)($section['source_asset_url'] ?? ''));
            if ($url !== '') {
                $references[] = ['type' => 'image', 'url' => $url, 'uri' => $url, 'name' => (string)($section['title'] ?? ''), 'role' => 'revision_source'];
            }
        }
        $references = self::normalizeReferences($references);
        $now = time();
        $batch = AigcCanvasAgentBatch::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => $projectId,
            'thread_id' => $threadId,
            'run_id' => (int)($route['agent_run_id'] ?? 0),
            'analysis_message_id' => $messageId,
            'request_id' => $requestId,
            'skill_key' => (string)($parent['skill_key'] ?? 'ecommerce_detail_page'),
            'status' => 'awaiting_initial_confirmation',
            'execution_mode' => 'batch',
            'batch_kind' => 'revision',
            'parent_batch_id' => $parentBatchId,
            'revision_no' => $revisionNo,
            'revision_instruction' => $instruction,
            'decision_json' => $revisionDecision,
            'scope_json' => array_merge((array)($revisionDecision['target_scope'] ?? []), [
                'propagates_to_remaining' => $propagatesToRemaining,
                'inherited_remaining_count' => count($remainingSections),
            ]),
            'total_count' => count($sections),
            'next_offset' => 0,
            'batch_size' => self::BATCH_SIZE,
            'current_wave' => 0,
            'notified_wave' => 0,
            'analysis_json' => (array)($parent['analysis_json'] ?? []),
            'sections_json' => $sections,
            'references_json' => $references,
            'media_config_json' => array_merge(
                (array)($parent['media_config_json'] ?? []),
                (array)($route['tool_options']['generate_image'] ?? []),
                (array)($decision['media_config_patch'] ?? [])
            ),
            'tasks_json' => [],
            'error' => '',
            'create_time' => $now,
            'update_time' => $now,
            'delete_time' => 0,
        ]);

        self::suppressPreviousContinuationMessages($tenantId, $userId, $parent->toArray(), $propagatesToRemaining);

        $execution = self::execute($tenantId, $userId, [
            'batch_id' => (int)$batch['id'],
            'action' => 'initial',
            'request_id' => $requestId . ':execute',
            'assistant_message_id' => $messageId,
        ]);
        $summary = trim((string)($decision['decision_trace_summary'] ?? $plan['summary'] ?? ''));
        if ($propagatesToRemaining && !empty($remainingSections)) {
            $summary = trim($summary . "\n\n这项修改要求已经同步到剩余 " . count($remainingSections) . ' 张的生成计划中。当前批次完成后，我会询问你是否继续生成。');
        }
        if ($summary === '') {
            $summary = '我会按本轮要求修改刚才的设计结果，保留未要求变更的内容，并把新版本放在原图旁边。';
        }
        return [
            'reply' => $summary,
            'tool_calls' => (array)($execution['tool_calls'] ?? []),
            'workspace_actions' => (array)($execution['workspace_actions'] ?? []),
            'assets' => [],
            'next_action' => 'execute_revision',
            'revision_batch_id' => (int)$batch['id'],
            'revision_of_batch_id' => $parentBatchId,
            'batch_id' => (int)$batch['id'],
            'batch' => (array)($execution['batch'] ?? self::formatBatch($batch->toArray())),
            'decision_trace_summary' => $summary,
        ];
    }

    public static function resumeRevisionContext(int $tenantId, int $userId, int $batchId): array
    {
        $batch = self::batchQuery($tenantId, $userId, $batchId)->findOrEmpty();
        if (
            $batch->isEmpty()
            || (string)$batch['batch_kind'] !== 'revision'
            || !in_array((string)$batch['status'], ['completed', 'partial_failed'], true)
            || (int)$batch['parent_batch_id'] <= 0
        ) {
            return $batch->isEmpty() ? [] : self::formatBatch($batch->toArray());
        }
        $scope = is_array($batch['scope_json'] ?? null) ? $batch['scope_json'] : [];
        if (!empty($scope['propagates_to_remaining'])) {
            return self::formatBatch($batch->toArray());
        }
        $decision = is_array($batch['decision_json'] ?? null) ? $batch['decision_json'] : [];
        $source = (string)($decision['target_scope']['source'] ?? $scope['source'] ?? '');
        if (empty($decision['changes']) || !in_array($source, ['active_batch', 'last_delivery'], true)) {
            return self::formatBatch($batch->toArray());
        }
        $parent = self::batchQuery($tenantId, $userId, (int)$batch['parent_batch_id'])->findOrEmpty();
        if ($parent->isEmpty()) {
            return self::formatBatch($batch->toArray());
        }
        $parentSections = array_values(array_filter((array)$parent['sections_json'], 'is_array'));
        $offset = (int)$parent['next_offset'];
        $remainingSections = array_slice($parentSections, $offset);
        $selectedKeys = array_values(array_unique(array_map(
            'strval',
            (array)($decision['target_scope']['section_keys'] ?? $scope['section_keys'] ?? [])
        )));
        $submittedKeys = array_column(array_slice($parentSections, 0, $offset), 'section_key');
        if (empty($remainingSections) || count(array_intersect($submittedKeys, $selectedKeys)) < count($submittedKeys)) {
            return self::formatBatch($batch->toArray());
        }

        $remainingAssets = array_map(static fn(array $section): array => [
            'section_index' => (int)($section['section_index'] ?? 0),
            'section_key' => (string)($section['section_key'] ?? ''),
            'title' => (string)($section['title'] ?? ''),
            'prompt' => (string)($section['image_prompt'] ?? ''),
            'copy_content' => is_array($section['copy_content'] ?? null) ? $section['copy_content'] : [],
            'url' => '',
            'node_id' => '',
            'target_element_id' => '',
            'status' => 'waiting',
            'ratio' => (string)($section['ratio'] ?? '3:4'),
        ], $remainingSections);
        $remainingDecision = $decision;
        $remainingDecision['target_scope']['section_keys'] = array_column($remainingAssets, 'section_key');
        $plan = RevisionPlanner::plan(
            $tenantId,
            $userId,
            (string)$batch['revision_instruction'],
            $remainingDecision,
            [
                'assets' => $remainingAssets,
                'design_analysis' => (array)$parent['analysis_json'],
            ]
        );
        $plannedRemaining = array_values(array_filter((array)($plan['sections'] ?? []), 'is_array'));
        if (count($plannedRemaining) !== count($remainingSections)) {
            throw new Exception('未能恢复完整的后续修订计划');
        }
        foreach ($plannedRemaining as $index => &$section) {
            $section['section_index'] = $offset + $index + 1;
        }
        unset($section);
        $allSections = array_values(array_merge((array)$batch['sections_json'], $plannedRemaining));
        $decision['target_scope']['section_keys'] = array_values(array_unique(array_merge(
            $selectedKeys,
            array_column($plannedRemaining, 'section_key')
        )));
        $scope = array_merge($scope, [
            'section_keys' => $decision['target_scope']['section_keys'],
            'propagates_to_remaining' => true,
            'inherited_remaining_count' => count($plannedRemaining),
        ]);
        $batch->save([
            'status' => 'awaiting_continue',
            'total_count' => count($allSections),
            'sections_json' => $allSections,
            'decision_json' => $decision,
            'scope_json' => $scope,
            'update_time' => time(),
        ]);
        self::suppressPreviousContinuationMessages($tenantId, $userId, $parent->toArray(), true);
        self::restoreRevisionContinuationMessage($tenantId, $userId, $batch->toArray(), count($plannedRemaining));
        return self::formatBatch($batch->toArray());
    }

    public static function execute(int $tenantId, int $userId, array $params): array
    {
        $batchId = (int)($params['batch_id'] ?? 0);
        $action = (string)($params['action'] ?? '');
        if (!in_array($action, ['initial', 'next', 'all_remaining'], true)) {
            throw new Exception('无效的批次执行动作');
        }
        $requestId = preg_replace('/[^a-zA-Z0-9_.:-]/', '', (string)($params['request_id'] ?? '')) ?? '';
        if ($requestId === '') {
            throw new Exception('缺少批次请求标识');
        }

        $mediaConfigPatch = self::normalizeMediaConfigPatch((array)($params['media_config_patch'] ?? []));
        $selection = Db::transaction(function () use ($tenantId, $userId, $batchId, $action, $mediaConfigPatch): array {
            $batch = self::batchQuery($tenantId, $userId, $batchId)->lock(true)->findOrEmpty();
            if ($batch->isEmpty()) {
                throw new Exception('生成批次不存在');
            }
            $status = (string)$batch['status'];
            $allowed = $action === 'initial'
                ? $status === 'awaiting_initial_confirmation'
                : $status === 'awaiting_continue';
            if (!$allowed) {
                if (in_array($status, ['running', 'running_all_remaining', 'completed', 'partial_failed'], true)) {
                    return ['batch' => $batch->toArray(), 'sections' => [], 'reused' => true];
                }
                throw new Exception('当前批次状态不能执行该操作');
            }
            $sections = (array)$batch['sections_json'];
            $offset = (int)$batch['next_offset'];
            $remaining = max(0, count($sections) - $offset);
            $limit = $action === 'all_remaining' ? $remaining : min((int)$batch['batch_size'], $remaining);
            $selected = array_slice($sections, $offset, $limit);
            if (empty($selected)) {
                throw new Exception('没有待生成的图片');
            }
            $wave = (int)$batch['current_wave'] + 1;
            $mediaConfig = array_merge((array)$batch['media_config_json'], $mediaConfigPatch);
            $batch->save([
                'status' => $action === 'all_remaining' ? 'running_all_remaining' : 'running',
                'execution_mode' => $action === 'all_remaining' ? 'all_remaining' : 'batch',
                'next_offset' => $offset + count($selected),
                'current_wave' => $wave,
                'media_config_json' => $mediaConfig,
                'update_time' => time(),
            ]);
            return ['batch' => $batch->toArray(), 'sections' => $selected, 'wave' => $wave, 'reused' => false];
        });

        if (!empty($selection['reused'])) {
            return ['batch' => self::formatBatch($selection['batch']), 'assistant_message' => null, 'tool_calls' => [], 'workspace_actions' => [], 'reused' => true];
        }
        $batch = $selection['batch'];
        $wave = (int)$selection['wave'];
        $sections = $selection['sections'];
        $now = time();
        $assistantMessageId = (int)($params['assistant_message_id'] ?? 0);
        $assistant = $assistantMessageId > 0 ? AigcCanvasAgentMessage::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'id' => $assistantMessageId,
            'delete_time' => 0,
        ])->findOrEmpty() : null;
        if (!$assistant || $assistant->isEmpty()) {
            $assistant = AigcCanvasAgentMessage::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => (int)$batch['project_id'],
            'thread_id' => (int)$batch['thread_id'],
            'role' => 'assistant',
            'content' => self::executionMessage($sections, (int)$batch['total_count']),
            'content_json' => [
                'skill_key' => 'ecommerce_detail_page',
                'batch_id' => (int)$batch['id'],
                'batch_wave' => $wave,
                'next_action' => 'execute_tool',
                'tool_calls' => [],
                'workspace_actions' => [],
            ],
            'status' => 'success',
            'meta_json' => [],
            'create_time' => $now,
            'update_time' => $now,
            'delete_time' => 0,
            ]);
        }

        $toolCalls = [];
        $workspaceActions = [];
        $tasks = (array)($batch['tasks_json'] ?? []);
        foreach ($sections as $section) {
            $sectionIndex = (int)($section['section_index'] ?? 0);
            $sectionKey = (string)($section['section_key'] ?? 'section_' . $sectionIndex);
            $revisionNo = (int)($batch['revision_no'] ?? 0);
            $targetId = 'ecommerce_batch_' . (int)$batch['id'] . '_' . $sectionKey . ($revisionNo > 0 ? '_r' . $revisionNo : '');
            $sectionReferences = self::referencesForSection((array)$batch['references_json'], $section);
            $input = array_merge((array)($batch['media_config_json'] ?? []), [
                'prompt' => (string)($section['image_prompt'] ?? ''),
                'project_id' => (int)$batch['project_id'],
                'quantity' => 1,
                'ratio' => (string)($section['ratio'] ?? '') ?: (string)(($batch['media_config_json']['ratio'] ?? '3:4')),
                'design_width' => 750,
                'target_element_id' => $targetId,
                'section_key' => $sectionKey,
                'section_index' => $sectionIndex,
                'batch_id' => (int)$batch['id'],
                'batch_kind' => (string)($batch['batch_kind'] ?? 'initial'),
                'parent_batch_id' => (int)($batch['parent_batch_id'] ?? 0),
                'revision_no' => $revisionNo,
                'source_node_id' => (string)($section['source_node_id'] ?? ''),
                'source_target_element_id' => (string)($section['source_target_element_id'] ?? ''),
                'source_section_key' => (string)($section['source_section_key'] ?? ''),
                'request_id' => 'batch:' . (int)$batch['id'] . ':' . $sectionKey,
                'reference_images' => self::referenceImages($sectionReferences),
                'reference_assets' => $sectionReferences,
            ]);
            try {
                $result = AigcCanvasAgentRuntimeService::executeExternalToolWithActions(
                    $tenantId,
                    $userId,
                    (int)$batch['project_id'],
                    (int)$batch['thread_id'],
                    (int)$assistant['id'],
                    'generate_image',
                    $input,
                    $input['prompt'],
                    ['uploaded_references' => $sectionReferences],
                    null,
                    1
                );
                $tool = (array)($result['tool_calls'][0] ?? []);
                $workspace = (array)($result['workspace_actions'][0] ?? []);
                $toolCalls = array_merge($toolCalls, (array)($result['tool_calls'] ?? []));
                $workspaceActions = array_merge($workspaceActions, (array)($result['workspace_actions'] ?? []));
                $tasks[] = [
                    'wave' => $wave,
                    'section_index' => $sectionIndex,
                    'section_key' => $sectionKey,
                    'title' => (string)($section['title'] ?? ''),
                    'target_element_id' => $targetId,
                    'source_node_id' => (string)($section['source_node_id'] ?? ''),
                    'revision_no' => $revisionNo,
                    'tool_call_id' => (int)($tool['id'] ?? 0),
                    'workspace_action_id' => (int)($workspace['id'] ?? 0),
                    'task_id' => (int)($tool['provider_task_id'] ?? $tool['output']['task_id'] ?? 0),
                    'status' => 'running',
                    'url' => '',
                    'error' => '',
                ];
            } catch (Exception $e) {
                $tasks[] = [
                    'wave' => $wave,
                    'section_index' => $sectionIndex,
                    'section_key' => $sectionKey,
                    'title' => (string)($section['title'] ?? ''),
                    'target_element_id' => $targetId,
                    'tool_call_id' => 0,
                    'workspace_action_id' => 0,
                    'task_id' => 0,
                    'status' => 'failed',
                    'url' => '',
                    'error' => $e->getMessage(),
                ];
            }
        }
        $batchModel = self::batchQuery($tenantId, $userId, (int)$batch['id'])->findOrEmpty();
        $batchModel->save(['tasks_json' => $tasks, 'update_time' => time()]);
        $contentJson = (array)$assistant['content_json'];
        $contentJson['tool_calls'] = $toolCalls;
        $contentJson['workspace_actions'] = $workspaceActions;
        $contentJson['batch'] = self::formatBatch($batchModel->toArray());
        $assistant->save(['content_json' => $contentJson, 'update_time' => time()]);
        return [
            'batch' => self::formatBatch($batchModel->toArray()),
            'assistant_message' => AigcCanvasAgentRuntimeService::formatMessage($assistant->toArray()),
            'tool_calls' => $toolCalls,
            'workspace_actions' => $workspaceActions,
            'reused' => false,
        ];
    }

    public static function status(int $tenantId, int $userId, array $params): array
    {
        $batchId = (int)($params['batch_id'] ?? 0);
        $batch = self::batchQuery($tenantId, $userId, $batchId)->findOrEmpty();
        if ($batch->isEmpty()) {
            throw new Exception('生成批次不存在');
        }
        $tasks = (array)$batch['tasks_json'];
        $currentWave = (int)$batch['current_wave'];
        $changed = false;
        foreach ($tasks as &$task) {
            if ((int)($task['wave'] ?? 0) !== $currentWave || in_array((string)($task['status'] ?? ''), self::TERMINAL_TASK_STATUSES, true)) {
                continue;
            }
            $taskId = (int)($task['task_id'] ?? 0);
            if ($taskId <= 0) {
                $task['status'] = 'failed';
                $task['error'] = (string)($task['error'] ?? '任务提交失败');
                $changed = true;
                continue;
            }
            try {
                $detail = AigcCanvasService::imageTaskDetail($tenantId, $userId, $taskId);
                $status = strtolower((string)($detail['status'] ?? 'running'));
                $url = self::resultUrl($detail);
                if ($url !== '') {
                    $status = 'success';
                } elseif (in_array($status, ['error', 'fail'], true)) {
                    $status = 'failed';
                } elseif (!in_array($status, self::TERMINAL_TASK_STATUSES, true)) {
                    $status = 'running';
                }
                $task['status'] = $status;
                $task['url'] = $url;
                $task['error'] = (string)($detail['error'] ?? '');
                self::syncWorkspaceAction($tenantId, $userId, $task, $detail);
                $changed = true;
            } catch (Exception $e) {
                $task['last_query_error'] = $e->getMessage();
            }
        }
        unset($task);
        if ($changed) {
            $batch->save(['tasks_json' => $tasks, 'update_time' => time()]);
        }

        $waveTasks = array_values(array_filter($tasks, static fn(array $task): bool => (int)($task['wave'] ?? 0) === $currentWave));
        $waveDone = !empty($waveTasks) && empty(array_filter($waveTasks, static fn(array $task): bool => !in_array((string)($task['status'] ?? ''), self::TERMINAL_TASK_STATUSES, true)));
        $newMessage = null;
        if ($waveDone && (int)$batch['notified_wave'] < $currentWave) {
            $newMessage = self::finishWave($tenantId, $userId, (int)$batch['id'], $currentWave);
            $batch = self::batchQuery($tenantId, $userId, $batchId)->findOrEmpty();
        }
        return [
            'batch' => self::formatBatch($batch->toArray()),
            'assistant_message' => $newMessage,
        ];
    }

    public static function formatBatch(array $batch): array
    {
        $tasks = array_values((array)($batch['tasks_json'] ?? []));
        $completed = count(array_filter($tasks, static fn(array $task): bool => (string)($task['status'] ?? '') === 'success'));
        $failed = count(array_filter($tasks, static fn(array $task): bool => in_array((string)($task['status'] ?? ''), ['failed', 'canceled', 'cancelled'], true)));
        $total = (int)($batch['total_count'] ?? 0);
        $remaining = max(0, $total - (int)($batch['next_offset'] ?? 0));
        return [
            'id' => (int)($batch['id'] ?? 0),
            'project_id' => (int)($batch['project_id'] ?? 0),
            'thread_id' => (int)($batch['thread_id'] ?? 0),
            'run_id' => (int)($batch['run_id'] ?? 0),
            'status' => (string)($batch['status'] ?? ''),
            'execution_mode' => (string)($batch['execution_mode'] ?? 'batch'),
            'batch_kind' => (string)($batch['batch_kind'] ?? 'initial'),
            'parent_batch_id' => (int)($batch['parent_batch_id'] ?? 0),
            'revision_no' => (int)($batch['revision_no'] ?? 0),
            'revision_instruction' => (string)($batch['revision_instruction'] ?? ''),
            'decision' => is_array($batch['decision_json'] ?? null) ? $batch['decision_json'] : [],
            'scope' => is_array($batch['scope_json'] ?? null) ? $batch['scope_json'] : [],
            'total_count' => $total,
            'submitted_count' => (int)($batch['next_offset'] ?? 0),
            'completed_count' => $completed,
            'failed_count' => $failed,
            'remaining_count' => $remaining,
            'next_batch_count' => min((int)($batch['batch_size'] ?? self::BATCH_SIZE), $remaining),
            'current_wave' => (int)($batch['current_wave'] ?? 0),
            'design_analysis' => is_array($batch['analysis_json'] ?? null) ? $batch['analysis_json'] : [],
            'planned_sections' => array_values((array)($batch['sections_json'] ?? [])),
            'tasks' => $tasks,
            'updated_at' => (int)($batch['update_time'] ?? 0),
        ];
    }

    private static function finishWave(int $tenantId, int $userId, int $batchId, int $wave): ?array
    {
        return Db::transaction(function () use ($tenantId, $userId, $batchId, $wave): ?array {
            $batch = self::batchQuery($tenantId, $userId, $batchId)->lock(true)->findOrEmpty();
            if ($batch->isEmpty() || (int)$batch['notified_wave'] >= $wave) {
                return null;
            }
            $tasks = (array)$batch['tasks_json'];
            $waveTasks = array_values(array_filter($tasks, static fn(array $task): bool => (int)($task['wave'] ?? 0) === $wave));
            $success = array_values(array_filter($waveTasks, static fn(array $task): bool => (string)($task['status'] ?? '') === 'success'));
            $failed = array_values(array_filter($waveTasks, static fn(array $task): bool => (string)($task['status'] ?? '') !== 'success'));
            $remaining = max(0, (int)$batch['total_count'] - (int)$batch['next_offset']);
            $messageBatch = $batch;
            if ($remaining > 0 && (string)$batch['execution_mode'] === 'batch') {
                $status = 'awaiting_continue';
                $nextAction = 'confirm_next_batch';
                $content = self::continuationMessage($success, $failed, $remaining, (int)$batch['batch_size']);
            } else {
                $allFailed = array_values(array_filter($tasks, static fn(array $task): bool => (string)($task['status'] ?? '') !== 'success'));
                $status = empty($allFailed) ? 'completed' : 'partial_failed';
                $nextAction = 'completed';
                $content = self::completionMessage($tasks);
                $scope = is_array($batch['scope_json'] ?? null) ? $batch['scope_json'] : [];
                if (
                    (string)($batch['batch_kind'] ?? '') === 'revision'
                    && empty($scope['propagates_to_remaining'])
                    && (int)($batch['parent_batch_id'] ?? 0) > 0
                ) {
                    $parent = self::batchQuery($tenantId, $userId, (int)$batch['parent_batch_id'])->lock(true)->findOrEmpty();
                    $parentRemaining = $parent->isEmpty()
                        ? 0
                        : max(0, (int)$parent['total_count'] - (int)$parent['next_offset']);
                    if ($parentRemaining > 0 && (string)$parent['status'] === 'awaiting_continue') {
                        $nextAction = 'confirm_next_batch';
                        $messageBatch = $parent;
                        $content = self::revisionContinuationMessage($tasks, $parentRemaining, (int)$parent['batch_size']);
                    }
                }
            }
            $batch->save(['status' => $status, 'notified_wave' => $wave, 'update_time' => time()]);
            $now = time();
            $message = AigcCanvasAgentMessage::create([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'project_id' => (int)$batch['project_id'],
                'thread_id' => (int)$batch['thread_id'],
                'role' => 'assistant',
                'content' => $content,
                'content_json' => [
                    'skill_key' => 'ecommerce_detail_page',
                    'batch_id' => (int)$messageBatch['id'],
                    'batch_wave' => $wave,
                    'batch' => self::formatBatch($messageBatch->toArray()),
                    'next_action' => $nextAction,
                    'tool_calls' => [],
                    'workspace_actions' => [],
                ],
                'status' => 'success',
                'meta_json' => [],
                'create_time' => $now,
                'update_time' => $now,
                'delete_time' => 0,
            ]);
            return AigcCanvasAgentRuntimeService::formatMessage($message->toArray());
        });
    }

    private static function syncWorkspaceAction(int $tenantId, int $userId, array $task, array $detail): void
    {
        $actionId = (int)($task['workspace_action_id'] ?? 0);
        if ($actionId <= 0) {
            return;
        }
        $action = AigcCanvasAgentWorkspaceAction::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'id' => $actionId,
            'delete_time' => 0,
        ])->findOrEmpty();
        if ($action->isEmpty()) {
            return;
        }
        $input = is_array($action['input_json'] ?? null) ? $action['input_json'] : [];
        $asset = is_array($input['asset'] ?? null) ? $input['asset'] : [];
        $url = self::resultUrl($detail);
        $asset['task_id'] = (int)($task['task_id'] ?? 0);
        $asset['status'] = (string)($task['status'] ?? 'running');
        if ($url !== '') {
            $asset['url'] = $url;
            $asset['image_url'] = $url;
        }
        $input['asset'] = $asset;
        $status = (string)($task['status'] ?? '') === 'failed' ? 'failed' : ($url !== '' ? 'applied' : 'pending');
        $action->save([
            'input_json' => $input,
            'status' => $status,
            'error' => (string)($task['error'] ?? ''),
            'update_time' => time(),
        ]);
        self::syncMessageWorkspaceAction($tenantId, $userId, $action->toArray());
        $toolCallId = (int)($task['tool_call_id'] ?? 0);
        if ($toolCallId > 0 && in_array((string)($task['status'] ?? ''), self::TERMINAL_TASK_STATUSES, true)) {
            AigcCanvasAgentToolCall::where(['tenant_id' => $tenantId, 'user_id' => $userId, 'id' => $toolCallId])->update([
                'status' => (string)($task['status'] ?? '') === 'success' ? 'success' : 'failed',
                'error' => (string)($task['error'] ?? ''),
                'finished_at' => time(),
                'update_time' => time(),
            ]);
        }
    }

    private static function syncMessageWorkspaceAction(int $tenantId, int $userId, array $action): void
    {
        $messageId = (int)($action['message_id'] ?? 0);
        if ($messageId <= 0) {
            return;
        }
        $message = AigcCanvasAgentMessage::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'id' => $messageId,
            'delete_time' => 0,
        ])->findOrEmpty();
        if ($message->isEmpty()) {
            return;
        }
        $content = is_array($message['content_json'] ?? null) ? $message['content_json'] : [];
        $actions = (array)($content['workspace_actions'] ?? []);
        $formatted = AigcCanvasAgentRuntimeService::formatWorkspaceAction($action);
        $matched = false;
        foreach ($actions as $index => $item) {
            if ((int)($item['id'] ?? 0) === (int)$formatted['id']) {
                $actions[$index] = $formatted;
                $matched = true;
                break;
            }
        }
        if (!$matched) {
            $actions[] = $formatted;
        }
        $content['workspace_actions'] = array_values($actions);
        $message->save(['content_json' => $content, 'update_time' => time()]);
    }

    private static function executionMessage(array $sections, int $total): string
    {
        $first = (int)($sections[0]['section_index'] ?? 1);
        $last = (int)($sections[count($sections) - 1]['section_index'] ?? $first);
        $titles = implode('、', array_map(static fn(array $section): string => (string)($section['title'] ?? ''), $sections));
        return "已确认设计方向，现在开始生成第 {$first}-{$last} 张（共 {$total} 张）：{$titles}。每张图片会使用独立提示词和独立任务。";
    }

    private static function continuationMessage(array $success, array $failed, int $remaining, int $batchSize): string
    {
        $titles = implode('、', array_map(static fn(array $task): string => (string)($task['title'] ?? ''), $success));
        $message = '这一批已经处理完成';
        if ($titles !== '') {
            $message .= '：' . $titles;
        }
        $message .= '。';
        if (!empty($failed)) {
            $message .= '其中 ' . count($failed) . ' 张生成失败，失败状态已保留在对应记录中。';
        }
        $next = min($batchSize, $remaining);
        return $message . "还剩 {$remaining} 张，你可以继续生成 {$next} 张，或一次生成全部剩余图片。";
    }

    private static function completionMessage(array $tasks): string
    {
        $success = count(array_filter($tasks, static fn(array $task): bool => (string)($task['status'] ?? '') === 'success'));
        $failed = count($tasks) - $success;
        return $failed > 0
            ? "整套详情页已处理完成：成功 {$success} 张，失败 {$failed} 张。成功结果已同步到画布和右侧生成记录。"
            : "整套详情页的 {$success} 张图片已经全部生成完成，结果已同步到画布和右侧生成记录。";
    }

    private static function revisionContinuationMessage(array $tasks, int $remaining, int $batchSize): string
    {
        $success = count(array_filter($tasks, static fn(array $task): bool => (string)($task['status'] ?? '') === 'success'));
        $failed = count($tasks) - $success;
        $next = min($batchSize, $remaining);
        $result = "本轮修订已经处理完成：成功 {$success} 张";
        if ($failed > 0) {
            $result .= "，失败 {$failed} 张";
        }
        return $result . "。原方案还有 {$remaining} 张尚未生成，是否继续生成下一批 {$next} 张？";
    }

    private static function resultUrl(array $detail): string
    {
        foreach ((array)($detail['results'] ?? $detail['images'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $url = trim((string)($item['image_url'] ?? $item['url'] ?? ''));
            if ($url !== '') {
                return $url;
            }
        }
        return trim((string)($detail['image_url'] ?? $detail['url'] ?? ''));
    }

    private static function normalizeReferences(array $references): array
    {
        $result = [];
        foreach ($references as $reference) {
            if (!is_array($reference)) {
                continue;
            }
            $url = trim((string)($reference['url'] ?? $reference['uri'] ?? ''));
            if ($url === '') {
                continue;
            }
            $type = strtolower((string)($reference['type'] ?? $reference['asset_type'] ?? 'image'));
            $result[$type . '|' . $url] = [
                'type' => $type,
                'url' => $url,
                'uri' => $url,
                'name' => (string)($reference['name'] ?? ''),
                'role' => (string)($reference['role'] ?? ($type === 'image' ? 'reference_image' : 'reference_' . $type)),
            ];
        }
        return array_values($result);
    }

    private static function referenceImages(array $references): array
    {
        return array_values(array_unique(array_filter(array_map(static function (array $reference): string {
            $type = strtolower((string)($reference['type'] ?? 'image'));
            return in_array($type, ['image', 'asset'], true) ? trim((string)($reference['url'] ?? '')) : '';
        }, array_filter($references, 'is_array')))));
    }

    private static function referencesForSection(array $references, array $section): array
    {
        $sourceUrl = trim((string)($section['source_asset_url'] ?? ''));
        return array_values(array_filter($references, static function ($reference) use ($sourceUrl): bool {
            if (!is_array($reference)) {
                return false;
            }
            if ((string)($reference['role'] ?? '') !== 'revision_source') {
                return true;
            }
            return $sourceUrl !== '' && trim((string)($reference['url'] ?? '')) === $sourceUrl;
        }));
    }

    private static function shouldPropagateRevisionToRemaining(array $decision, array $delivery, array $parent): bool
    {
        $remaining = max(0, (int)($parent['total_count'] ?? 0) - (int)($parent['next_offset'] ?? 0));
        if ($remaining <= 0 || empty($decision['changes'])) {
            return false;
        }
        $source = (string)($decision['target_scope']['source'] ?? '');
        if (!in_array($source, ['active_batch', 'last_delivery'], true)) {
            return false;
        }
        $available = array_values(array_filter(array_map(
            static fn(array $asset): string => (string)($asset['section_key'] ?? ''),
            array_filter((array)($delivery['assets'] ?? []), 'is_array')
        )));
        $selected = array_values(array_intersect(
            $available,
            array_map('strval', (array)($decision['target_scope']['section_keys'] ?? []))
        ));
        return !empty($available) && count(array_unique($selected)) === count(array_unique($available));
    }

    private static function suppressPreviousContinuationMessages(
        int $tenantId,
        int $userId,
        array $parent,
        bool $superseded
    ): void {
        if ($superseded) {
            AigcCanvasAgentBatch::where([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'id' => (int)($parent['id'] ?? 0),
                'delete_time' => 0,
            ])->update(['status' => 'superseded', 'update_time' => time()]);
            $parent['status'] = 'superseded';
        }
        $messages = AigcCanvasAgentMessage::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'thread_id' => (int)($parent['thread_id'] ?? 0),
            'delete_time' => 0,
        ])->select();
        foreach ($messages as $message) {
            $content = is_array($message['content_json'] ?? null) ? $message['content_json'] : [];
            if ((int)($content['batch_id'] ?? 0) !== (int)($parent['id'] ?? 0)) {
                continue;
            }
            $content['batch'] = self::formatBatch($parent);
            $content['next_action'] = $superseded ? 'superseded' : 'revision_in_progress';
            $message->save(['content_json' => $content, 'update_time' => time()]);
        }
    }

    private static function restoreRevisionContinuationMessage(
        int $tenantId,
        int $userId,
        array $batch,
        int $remaining
    ): void {
        $messages = AigcCanvasAgentMessage::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'thread_id' => (int)($batch['thread_id'] ?? 0),
            'role' => 'assistant',
            'delete_time' => 0,
        ])->order('id', 'desc')->select();
        $updatedPrompt = false;
        foreach ($messages as $message) {
            $content = is_array($message['content_json'] ?? null) ? $message['content_json'] : [];
            if ((int)($content['batch_id'] ?? 0) !== (int)($batch['id'] ?? 0)) {
                continue;
            }
            $content['batch'] = self::formatBatch($batch);
            if (!$updatedPrompt && (string)($content['next_action'] ?? '') === 'completed') {
                $content['next_action'] = 'confirm_next_batch';
                $message->save([
                    'content' => "当前 5 张修订已经完成，追加要求也已经写入剩余 {$remaining} 张的生成计划。是否继续生成下一批 " . min(self::BATCH_SIZE, $remaining) . ' 张？',
                    'content_json' => $content,
                    'update_time' => time(),
                ]);
                $updatedPrompt = true;
                continue;
            }
            $message->save(['content_json' => $content, 'update_time' => time()]);
        }
    }

    private static function normalizeMediaConfigPatch(array $source): array
    {
        $result = [];
        foreach (['ratio', 'quality', 'model', 'channel', 'mode'] as $key) {
            $value = trim((string)($source[$key] ?? ''));
            if ($value !== '') {
                $result[$key] = mb_substr($value, 0, 120, 'UTF-8');
            }
        }
        if (isset($source['duration']) && is_numeric($source['duration'])) {
            $result['duration'] = max(1, min(60, (int)$source['duration']));
        }
        return $result;
    }

    private static function batchQuery(int $tenantId, int $userId, int $batchId)
    {
        return AigcCanvasAgentBatch::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'id' => $batchId,
            'delete_time' => 0,
        ]);
    }
}
