<?php

namespace app\common\service\app\aigc_canvas\agent\replay;

use app\common\model\app\aigc_canvas\AigcCanvasAgentMessage;
use app\common\model\app\aigc_canvas\AigcCanvasAgentThread;
use app\common\service\app\aigc_canvas\AigcCanvasAgentRuntimeService;
use Exception;
use think\facade\Db;

final class CanvasAgentReplayService
{
    private const SHARE_META_KEY = 'agent_replay_share';

    public static function detail(int $tenantId, int $userId, array $params = []): array
    {
        $thread = self::resolveOwnedThread($tenantId, $userId, $params);
        return self::buildReplay($thread, false);
    }

    public static function share(int $tenantId, int $userId, array $params = []): array
    {
        $thread = self::resolveOwnedThread($tenantId, $userId, $params);
        $meta = is_array($thread['meta_json'] ?? null) ? $thread['meta_json'] : [];
        $share = is_array($meta[self::SHARE_META_KEY] ?? null) ? $meta[self::SHARE_META_KEY] : [];
        $token = trim((string)($share['token'] ?? ''));
        if ($token === '') {
            $token = bin2hex(random_bytes(16));
        }
        $now = time();
        $share = [
            'token' => $token,
            'enabled' => 1,
            'created_at' => (int)($share['created_at'] ?? $now),
            'updated_at' => $now,
        ];
        $meta[self::SHARE_META_KEY] = $share;
        $threadModel = AigcCanvasAgentThread::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'id' => (int)$thread['id'],
            'delete_time' => 0,
        ])->findOrEmpty();
        if ($threadModel->isEmpty()) {
            throw new Exception('Agent thread not found');
        }
        $threadModel->save([
            'meta_json' => $meta,
            'update_time' => $now,
        ]);

        return [
            'token' => $token,
            'thread_id' => (int)$thread['id'],
            'project_id' => (int)($thread['project_id'] ?? 0),
            'share_path' => '/app/aigc_canvas/replay?share_token=' . rawurlencode($token),
        ];
    }

    public static function shared(int $tenantId, array $params = []): array
    {
        $token = self::normalizeToken((string)($params['share_token'] ?? $params['token'] ?? ''));
        if ($token === '') {
            throw new Exception('share_token is required');
        }
        $query = AigcCanvasAgentThread::where('delete_time', 0)
            ->whereLike('meta_json', '%' . $token . '%');
        if ($tenantId > 0) {
            $query->where('tenant_id', $tenantId);
        }
        $thread = $query->findOrEmpty();
        if ($thread->isEmpty()) {
            throw new Exception('Replay share not found');
        }
        $row = $thread->toArray();
        $share = is_array($row['meta_json'][self::SHARE_META_KEY] ?? null)
            ? $row['meta_json'][self::SHARE_META_KEY]
            : [];
        if ((int)($share['enabled'] ?? 0) !== 1 || !hash_equals((string)($share['token'] ?? ''), $token)) {
            throw new Exception('Replay share not found');
        }
        return self::buildReplay($row, true);
    }

    private static function resolveOwnedThread(int $tenantId, int $userId, array $params): array
    {
        $threadId = (int)($params['thread_id'] ?? $params['id'] ?? 0);
        if ($threadId <= 0) {
            $requestId = trim((string)($params['request_id'] ?? ''));
            if ($requestId !== '') {
                $run = Db::name('aigc_canvas_agent_run')->where([
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'request_id' => mb_substr($requestId, 0, 96, 'UTF-8'),
                    'delete_time' => 0,
                ])->find();
                $threadId = (int)($run['thread_id'] ?? 0);
            }
        }
        if ($threadId <= 0) {
            throw new Exception('thread_id is required');
        }
        $thread = AigcCanvasAgentThread::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'id' => $threadId,
            'delete_time' => 0,
        ])->findOrEmpty();
        if ($thread->isEmpty()) {
            throw new Exception('Agent thread not found');
        }
        return $thread->toArray();
    }

    private static function buildReplay(array $thread, bool $public): array
    {
        $tenantId = (int)($thread['tenant_id'] ?? 0);
        $userId = (int)($thread['user_id'] ?? 0);
        $threadId = (int)($thread['id'] ?? 0);
        $messages = AigcCanvasAgentMessage::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'thread_id' => $threadId,
            'delete_time' => 0,
        ])->order('id', 'asc')->limit(300)->select()->toArray();
        $runs = Db::name('aigc_canvas_agent_run')->where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'thread_id' => $threadId,
            'delete_time' => 0,
        ])->order('id', 'asc')->limit(100)->select()->toArray();
        $runIds = array_values(array_filter(array_map(static fn(array $row): int => (int)($row['id'] ?? 0), $runs)));
        $steps = [];
        if (!empty($runIds)) {
            $steps = Db::name('aigc_canvas_agent_step')
                ->where('tenant_id', $tenantId)
                ->whereIn('run_id', $runIds)
                ->order('id', 'asc')
                ->limit(300)
                ->select()
                ->toArray();
        }
        $toolCalls = Db::name('aigc_canvas_agent_tool_call')->where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'thread_id' => $threadId,
            'delete_time' => 0,
        ])->order('id', 'asc')->limit(200)->select()->toArray();
        $actions = Db::name('aigc_canvas_agent_workspace_action')->where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'thread_id' => $threadId,
            'delete_time' => 0,
        ])->order('id', 'asc')->limit(200)->select()->toArray();
        $turns = [];
        $turnEvents = [];
        $subtasks = [];
        try {
            $turns = Db::name('aigc_canvas_agent_turn')->where([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'thread_id' => $threadId,
                'delete_time' => 0,
            ])->order('id', 'asc')->limit(200)->select()->toArray();
            $turnIds = array_values(array_filter(array_map(static fn(array $row): int => (int)($row['id'] ?? 0), $turns)));
            if ($turnIds !== []) {
                $turnEvents = Db::name('aigc_canvas_agent_turn_event')->whereIn('turn_id', $turnIds)->order('turn_id', 'asc')->order('sequence', 'asc')->limit(1000)->select()->toArray();
            }
        } catch (\Throwable) {
            $turns = [];
            $turnEvents = [];
        }
        if ($runIds !== []) {
            try {
                $subtasks = Db::name('aigc_canvas_agent_subtask')->whereIn('parent_run_id', $runIds)
                    ->order('parent_run_id', 'asc')->order('sequence', 'asc')->limit(300)->select()->toArray();
            } catch (\Throwable) {
                $subtasks = [];
            }
        }

        return [
            'public' => $public,
            'thread' => self::publicThread($thread, $public),
            'messages' => array_map([self::class, 'message'], $messages),
            'runs' => array_map([self::class, 'run'], $runs),
            'sub_agent_tree' => self::subAgentTree($runs),
            'subtasks' => array_map([self::class, 'subtask'], $subtasks),
            'steps' => array_map([self::class, 'step'], $steps),
            'tool_calls' => array_map([self::class, 'toolCall'], $toolCalls),
            'workspace_actions' => array_map([self::class, 'workspaceAction'], $actions),
            'canvas_diffs' => self::canvasDiffs($actions),
            'turns' => array_map([self::class, 'turn'], $turns),
            'turn_events' => array_map([self::class, 'turnEvent'], $turnEvents),
            'revision_context' => self::revisionContext($messages, $runs),
            'summary' => self::summary($messages, $runs, $toolCalls, $actions) + ['turn_count' => count($turns)],
        ];
    }

    private static function publicThread(array $row, bool $public): array
    {
        $data = AigcCanvasAgentRuntimeService::formatThread($row);
        if (!$public) {
            $data['share'] = is_array($row['meta_json'][self::SHARE_META_KEY] ?? null)
                ? $row['meta_json'][self::SHARE_META_KEY]
                : [];
        }
        return $data;
    }

    private static function message(array $row): array
    {
        $data = AigcCanvasAgentRuntimeService::formatMessage($row);
        $data['content_json'] = self::scrub($data['content_json']);
        return $data;
    }

    private static function run(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'project_id' => (int)($row['project_id'] ?? 0),
            'thread_id' => (int)($row['thread_id'] ?? 0),
            'request_id' => (string)($row['request_id'] ?? ''),
            'agent_code' => (string)($row['agent_code'] ?? ''),
            'parent_run_id' => (int)($row['parent_run_id'] ?? 0),
            'sub_agent_code' => (string)($row['sub_agent_code'] ?? ''),
            'depth' => (int)($row['depth'] ?? 0),
            'sequence' => (int)($row['sequence'] ?? 0),
            'handoff' => self::scrub(self::decode($row['handoff_json'] ?? [])),
            'status' => (string)($row['status'] ?? ''),
            'input' => self::scrub(self::decode($row['input_json'] ?? [])),
            'output' => self::scrub(self::decode($row['output_json'] ?? [])),
            'error' => (string)($row['error'] ?? ''),
            'created_at' => (int)($row['create_time'] ?? 0),
            'updated_at' => (int)($row['update_time'] ?? 0),
        ];
    }

    private static function step(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'run_id' => (int)($row['run_id'] ?? 0),
            'agent_code' => (string)($row['agent_code'] ?? ''),
            'step_type' => (string)($row['step_type'] ?? ''),
            'status' => (string)($row['status'] ?? ''),
            'input' => self::scrub(self::decode($row['input_json'] ?? [])),
            'output' => self::scrub(self::decode($row['output_json'] ?? [])),
            'created_at' => (int)($row['create_time'] ?? 0),
        ];
    }

    private static function turn(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'request_id' => (string)($row['request_id'] ?? ''),
            'status' => (string)($row['status'] ?? ''),
            'execution_mode' => (string)($row['execution_mode'] ?? ''),
            'iteration_count' => (int)($row['iteration_count'] ?? 0),
            'started_at_ms' => (int)($row['started_at_ms'] ?? 0),
            'first_token_at' => (int)($row['first_token_at'] ?? 0),
            'first_token_at_ms' => (int)($row['first_token_at_ms'] ?? 0),
            'completed_at' => (int)($row['completed_at'] ?? 0),
            'completed_at_ms' => (int)($row['completed_at_ms'] ?? 0),
            'fallback_reason' => (string)($row['fallback_reason'] ?? ''),
            'input' => self::scrub(self::decode($row['input_json'] ?? [])),
            'output' => self::scrub(self::decode($row['output_json'] ?? [])),
            'error' => (string)($row['error'] ?? ''),
            'created_at' => (int)($row['create_time'] ?? 0),
        ];
    }

    private static function turnEvent(array $row): array
    {
        return [
            'turn_id' => (int)($row['turn_id'] ?? 0),
            'sequence' => (int)($row['sequence'] ?? 0),
            'event_type' => (string)($row['event_type'] ?? ''),
            'payload' => self::scrub(self::decode($row['payload_json'] ?? [])),
            'created_at' => (int)($row['create_time'] ?? 0),
            'created_at_ms' => (int)($row['create_time_ms'] ?? 0),
        ];
    }

    private static function subtask(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'parent_run_id' => (int)($row['parent_run_id'] ?? 0),
            'child_run_id' => (int)($row['child_run_id'] ?? 0),
            'agent_code' => (string)($row['agent_code'] ?? ''),
            'sequence' => (int)($row['sequence'] ?? 0),
            'status' => (string)($row['status'] ?? ''),
            'result' => self::scrub(self::decode($row['result_json'] ?? [])),
            'error' => (string)($row['error'] ?? ''),
            'created_at' => (int)($row['create_time'] ?? 0),
            'finished_at' => (int)($row['finish_time'] ?? 0),
        ];
    }

    private static function toolCall(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'message_id' => (int)($row['message_id'] ?? 0),
            'request_id' => (string)($row['request_id'] ?? ''),
            'tool_code' => (string)($row['tool_code'] ?? ''),
            'status' => (string)($row['status'] ?? ''),
            'input' => self::scrub(self::decode($row['input_json'] ?? [])),
            'output' => self::scrub(self::decode($row['output_json'] ?? [])),
            'error' => (string)($row['error'] ?? ''),
            'error_code' => (string)($row['error_code'] ?? ''),
            'provider_task_id' => (string)($row['provider_task_id'] ?? ''),
            'duration_seconds' => max(0, (int)($row['finished_at'] ?? 0) - (int)($row['started_at'] ?? 0)),
            'created_at' => (int)($row['create_time'] ?? 0),
        ];
    }

    private static function workspaceAction(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'message_id' => (int)($row['message_id'] ?? 0),
            'tool_call_id' => (int)($row['tool_call_id'] ?? 0),
            'action_type' => (string)($row['action_type'] ?? ''),
            'status' => (string)($row['status'] ?? ''),
            'input' => self::scrub(self::decode($row['input_json'] ?? [])),
            'result' => self::scrub(self::decode($row['result_json'] ?? [])),
            'error' => (string)($row['error'] ?? ''),
            'created_at' => (int)($row['create_time'] ?? 0),
        ];
    }

    private static function canvasDiffs(array $actions): array
    {
        $items = [];
        foreach ($actions as $row) {
            $input = self::decode($row['input_json'] ?? []);
            $result = self::decode($row['result_json'] ?? []);
            $actionType = (string)($row['action_type'] ?? '');
            $proposal = is_array($input['proposal'] ?? null) ? $input['proposal'] : [];
            $operation = (string)($result['operation'] ?? $proposal['operation'] ?? '');
            if ($actionType === 'canvas_mutation' && $operation !== '') {
                $items[] = self::scrub([
                    'id' => (int)($row['id'] ?? 0),
                    'action_type' => $actionType,
                    'operation' => $operation,
                    'status' => (string)($row['status'] ?? ''),
                    'before_elements' => array_values((array)($result['before_elements'] ?? [])),
                    'after_elements' => array_values((array)($result['after_elements'] ?? [])),
                    'element_ids' => array_values((array)($result['element_ids'] ?? $proposal['element_ids'] ?? [])),
                    'error' => (string)($row['error'] ?? ''),
                    'created_at' => (int)($row['create_time'] ?? 0),
                ]);
                continue;
            }
            if ($actionType === 'apply_json_canvas') {
                $canvas = is_array($input['canvas_json'] ?? null) ? $input['canvas_json'] : [];
                $items[] = self::scrub([
                    'id' => (int)($row['id'] ?? 0),
                    'action_type' => $actionType,
                    'operation' => 'apply_json_canvas',
                    'status' => (string)($row['status'] ?? ''),
                    'added_count' => count((array)($result['node_ids'] ?? [])),
                    'updated_count' => count((array)($result['updated_node_ids'] ?? [])),
                    'protocol_action_count' => count((array)($canvas['actions'] ?? [])),
                    'error' => (string)($row['error'] ?? ''),
                    'created_at' => (int)($row['create_time'] ?? 0),
                ]);
            }
        }
        return $items;
    }

    private static function revisionContext(array $messages, array $runs): array
    {
        $items = [];
        foreach ($messages as $message) {
            $json = is_array($message['content_json'] ?? null) ? $message['content_json'] : [];
            if (($json['conversation_mode'] ?? '') !== 'follow_up' && empty($json['revision_batch_id'])) {
                continue;
            }
            $items[] = self::scrub([
                'message_id' => (int)($message['id'] ?? 0),
                'operation' => (string)($json['operation'] ?? ''),
                'target_scope' => is_array($json['target_scope'] ?? null) ? $json['target_scope'] : [],
                'requested_changes' => is_array($json['requested_changes'] ?? null) ? $json['requested_changes'] : [],
                'preserved_constraints' => is_array($json['preserved_constraints'] ?? null) ? $json['preserved_constraints'] : [],
                'revision_batch_id' => (int)($json['revision_batch_id'] ?? 0),
                'revision_of_batch_id' => (int)($json['revision_of_batch_id'] ?? 0),
                'decision_trace_summary' => (string)($json['decision_trace_summary'] ?? ''),
            ]);
        }
        foreach ($runs as $run) {
            $output = self::decode($run['output_json'] ?? []);
            if (($output['conversation_mode'] ?? '') !== 'follow_up' && empty($output['revision_batch_id'])) {
                continue;
            }
            $items[] = self::scrub([
                'run_id' => (int)($run['id'] ?? 0),
                'request_id' => (string)($run['request_id'] ?? ''),
                'operation' => (string)($output['operation'] ?? ''),
                'target_scope' => is_array($output['target_scope'] ?? null) ? $output['target_scope'] : [],
                'requested_changes' => is_array($output['requested_changes'] ?? null) ? $output['requested_changes'] : [],
                'preserved_constraints' => is_array($output['preserved_constraints'] ?? null) ? $output['preserved_constraints'] : [],
                'revision_batch_id' => (int)($output['revision_batch_id'] ?? 0),
                'revision_of_batch_id' => (int)($output['revision_of_batch_id'] ?? 0),
                'decision_trace_summary' => (string)($output['decision_trace_summary'] ?? ''),
            ]);
        }
        return $items;
    }

    private static function summary(array $messages, array $runs, array $toolCalls, array $actions): array
    {
        $clarifications = 0;
        foreach ($messages as $message) {
            $json = is_array($message['content_json'] ?? null) ? $message['content_json'] : [];
            if (($json['next_action'] ?? '') === 'clarify' || !empty($json['clarify_question'])) {
                $clarifications++;
            }
        }
        return [
            'message_count' => count($messages),
            'run_count' => count($runs),
            'tool_call_count' => count($toolCalls),
            'workspace_action_count' => count($actions),
            'clarification_count' => $clarifications,
        ];
    }

    private static function subAgentTree(array $runs): array
    {
        $nodes = [];
        foreach ($runs as $run) {
            $id = (int)($run['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $nodes[$id] = [
                'id' => $id,
                'parent_run_id' => (int)($run['parent_run_id'] ?? 0),
                'agent_code' => (string)($run['sub_agent_code'] ?? $run['agent_code'] ?? ''),
                'status' => (string)($run['status'] ?? ''),
                'children' => [],
            ];
        }
        $roots = [];
        foreach ($nodes as $id => &$node) {
            $parentId = (int)$node['parent_run_id'];
            if ($parentId > 0 && isset($nodes[$parentId])) {
                $nodes[$parentId]['children'][] = &$node;
            } else {
                $roots[] = &$node;
            }
        }
        unset($node);
        return $roots;
    }

    private static function decode($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    private static function scrub($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        $deny = [
            'api_key' => true,
            'access_key' => true,
            'secret_key' => true,
            'authorization' => true,
            'password' => true,
            'market_sku_id' => true,
            'sku_id' => true,
            'sku' => true,
            'provider_sku' => true,
        ];
        $result = [];
        foreach ($value as $key => $item) {
            if (isset($deny[strtolower((string)$key)])) {
                continue;
            }
            $result[$key] = is_array($item) ? self::scrub($item) : $item;
        }
        return $result;
    }

    private static function normalizeToken(string $token): string
    {
        return mb_substr(preg_replace('/[^a-zA-Z0-9]/', '', $token) ?? '', 0, 64, 'UTF-8');
    }
}
