<?php

namespace app\common\service\app\aigc_canvas\agent\memory;

use app\common\model\app\aigc_canvas\AigcCanvasAgentBatch;
use app\common\model\app\aigc_canvas\AigcCanvasAgentMessage;
use app\common\model\app\aigc_canvas\AigcCanvasAgentWorkspaceAction;

final class ConversationDeliveryContext
{
    public static function build(
        int $tenantId,
        int $userId,
        int $projectId,
        int $threadId,
        array $requestContext = []
    ): array {
        if ($threadId <= 0) {
            return self::emptyContext($requestContext, $tenantId, $projectId);
        }

        $messages = AigcCanvasAgentMessage::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'thread_id' => $threadId,
            'delete_time' => 0,
        ])->order('id', 'desc')->limit(12)->select()->toArray();
        $messages = array_reverse(array_map(static function (array $message): array {
            $json = is_array($message['content_json'] ?? null) ? $message['content_json'] : [];
            return [
                'id' => (int)($message['id'] ?? 0),
                'role' => (string)($message['role'] ?? ''),
                'content' => mb_substr((string)($message['content'] ?? ''), 0, 2400, 'UTF-8'),
                'intent' => (string)($json['intent'] ?? ''),
                'operation' => (string)($json['operation'] ?? ''),
                'batch_id' => (int)($json['batch_id'] ?? 0),
            ];
        }, $messages));

        $batches = AigcCanvasAgentBatch::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'thread_id' => $threadId,
            'delete_time' => 0,
        ])->order('id', 'desc')->limit(6)->select()->toArray();
        $active = [];
        $lastDelivery = [];
        foreach ($batches as $batch) {
            $delivery = self::deliveryFromBatch($tenantId, $userId, $batch);
            if (empty($active) && in_array((string)($batch['status'] ?? ''), [
                'awaiting_initial_confirmation', 'running', 'running_all_remaining', 'awaiting_continue',
            ], true)) {
                $active = $delivery;
            }
            if (empty($lastDelivery) && !empty($delivery['assets'])) {
                $lastDelivery = $delivery;
            }
        }

        return [
            'recent_messages' => $messages,
            'active_batch' => $active,
            'last_delivery' => $lastDelivery,
            'unfinished_work' => !empty($active) ? [
                'batch_id' => (int)($active['batch_id'] ?? 0),
                'status' => (string)($active['status'] ?? ''),
                'remaining_count' => (int)($active['remaining_count'] ?? 0),
            ] : [],
            'selected_canvas_elements' => array_values((array)($requestContext['selected_elements'] ?? [])),
            'uploaded_references' => array_values((array)($requestContext['uploaded_references'] ?? [])),
            'project_memory' => ProjectMemoryService::load($tenantId, $projectId),
        ];
    }

    private static function deliveryFromBatch(int $tenantId, int $userId, array $batch): array
    {
        $tasks = array_values(array_filter((array)($batch['tasks_json'] ?? []), 'is_array'));
        $sections = array_values(array_filter((array)($batch['sections_json'] ?? []), 'is_array'));
        $sectionMap = [];
        foreach ($sections as $section) {
            $sectionMap[(string)($section['section_key'] ?? '')] = $section;
        }
        $actionIds = array_values(array_filter(array_map(
            static fn(array $task): int => (int)($task['workspace_action_id'] ?? 0),
            $tasks
        )));
        $actions = [];
        if (!empty($actionIds)) {
            $rows = AigcCanvasAgentWorkspaceAction::where([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'delete_time' => 0,
            ])->whereIn('id', $actionIds)->select()->toArray();
            foreach ($rows as $row) {
                $actions[(int)$row['id']] = $row;
            }
        }

        $assets = [];
        foreach ($tasks as $task) {
            $key = (string)($task['section_key'] ?? '');
            $section = $sectionMap[$key] ?? [];
            $action = $actions[(int)($task['workspace_action_id'] ?? 0)] ?? [];
            $input = is_array($action['input_json'] ?? null) ? $action['input_json'] : [];
            $result = is_array($action['result_json'] ?? null) ? $action['result_json'] : [];
            $url = trim((string)($task['url'] ?? $input['asset']['url'] ?? ''));
            $nodeId = trim((string)($result['node_id'] ?? $task['node_id'] ?? ''));
            $assets[] = [
                'section_index' => (int)($task['section_index'] ?? $section['section_index'] ?? 0),
                'section_key' => $key,
                'title' => (string)($task['title'] ?? $section['title'] ?? ''),
                'prompt' => (string)($section['image_prompt'] ?? $input['prompt'] ?? ''),
                'copy_content' => is_array($section['copy_content'] ?? null) ? $section['copy_content'] : [],
                'url' => $url,
                'node_id' => $nodeId,
                'target_element_id' => (string)($task['target_element_id'] ?? ''),
                'status' => (string)($task['status'] ?? ''),
                'ratio' => (string)($section['ratio'] ?? $input['asset']['ratio'] ?? ''),
            ];
        }
        return [
            'type' => 'image_batch',
            'batch_id' => (int)($batch['id'] ?? 0),
            'batch_kind' => (string)($batch['batch_kind'] ?? 'initial'),
            'parent_batch_id' => (int)($batch['parent_batch_id'] ?? 0),
            'status' => (string)($batch['status'] ?? ''),
            'total_count' => (int)($batch['total_count'] ?? count($sections)),
            'remaining_count' => max(0, (int)($batch['total_count'] ?? 0) - (int)($batch['next_offset'] ?? 0)),
            'section_keys' => array_values(array_filter(array_map(static fn(array $item): string => (string)($item['section_key'] ?? ''), $assets))),
            'assets' => $assets,
            'design_analysis' => is_array($batch['analysis_json'] ?? null) ? $batch['analysis_json'] : [],
            'media_config' => is_array($batch['media_config_json'] ?? null) ? $batch['media_config_json'] : [],
        ];
    }

    private static function emptyContext(array $requestContext, int $tenantId, int $projectId): array
    {
        return [
            'recent_messages' => [],
            'active_batch' => [],
            'last_delivery' => [],
            'unfinished_work' => [],
            'selected_canvas_elements' => array_values((array)($requestContext['selected_elements'] ?? [])),
            'uploaded_references' => array_values((array)($requestContext['uploaded_references'] ?? [])),
            'project_memory' => ProjectMemoryService::load($tenantId, $projectId),
        ];
    }
}
