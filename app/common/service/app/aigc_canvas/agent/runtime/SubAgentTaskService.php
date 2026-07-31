<?php

namespace app\common\service\app\aigc_canvas\agent\runtime;

use app\common\model\app\aigc_canvas\AigcCanvasAgentMessage;
use app\common\model\app\aigc_canvas\AigcCanvasAgentThread;
use app\common\service\app\aigc_canvas\AigcCanvasAgentRuntimeService;
use app\common\service\app\aigc_canvas\agent\memory\CanvasSnapshotBuilder;
use app\common\service\app\aigc_canvas\agent\orchestrator\AgentExecutionContext;
use Exception;
use think\facade\Db;

/**
 * Durable execution for complex-work delegates. Multiple worker processes can
 * claim sibling rows concurrently; the last completed sibling resumes the
 * parent Agent exactly once to synthesize the delivery.
 */
final class SubAgentTaskService
{
    private const MAX_ATTEMPTS = 2;

    public static function dispatch(
        AgentExecutionContext $context,
        array $tasks,
        int $parentRunId,
        int $turnId,
        int $iterations,
        string $requestId,
        string $request,
        array $canvasContext,
        int $assistantMessageId
    ): array {
        if ($parentRunId <= 0 || $assistantMessageId <= 0) {
            throw new Exception('Sub-agent parent context is unavailable');
        }
        $scheduled = [];
        foreach (array_values($tasks) as $sequence => $task) {
            $agentCode = trim((string)($task['agent_code'] ?? ''));
            $focus = mb_substr(trim((string)($task['task'] ?? $task['focus'] ?? '')), 0, 1200, 'UTF-8');
            if ($agentCode === '' || $focus === '') {
                continue;
            }
            $childRunId = AgentTraceLogger::startRun(
                $context->tenantId(), $context->userId(), $context->projectId(), $context->threadId(),
                'dynamic_sub_agent', ['request' => $request, 'task' => $focus],
                $requestId . ':sub:' . $agentCode . ':' . $sequence, $parentRunId, $agentCode, 1, $sequence + 1,
                ['delegated_by' => 'agent_loop', 'delivery_mode' => 'durable_parallel']
            );
            AgentTraceLogger::queueRun($childRunId);
            $payload = [
                'tenant_id' => $context->tenantId(),
                'user_id' => $context->userId(),
                'project_id' => $context->projectId(),
                'thread_id' => $context->threadId(),
                'parent_run_id' => $parentRunId,
                'parent_turn_id' => $turnId,
                'parent_iterations' => $iterations,
                'assistant_message_id' => $assistantMessageId,
                'request_id' => $requestId,
                'request' => $request,
                'agent_code' => $agentCode,
                'focus' => $focus,
                'canvas_context' => CanvasSnapshotBuilder::compact($canvasContext),
                'child_run_id' => $childRunId,
            ];
            $id = (int)Db::name('aigc_canvas_agent_subtask')->insertGetId([
                'tenant_id' => $context->tenantId(),
                'user_id' => $context->userId(),
                'project_id' => $context->projectId(),
                'thread_id' => $context->threadId(),
                'parent_run_id' => $parentRunId,
                'child_run_id' => $childRunId,
                'request_id' => mb_substr($requestId, 0, 96, 'UTF-8'),
                'agent_code' => $agentCode,
                'sequence' => $sequence + 1,
                'status' => 'pending',
                'payload_json' => self::json($payload),
                'result_json' => self::json([]),
                'error' => '',
                'attempts' => 0,
                'max_attempts' => self::MAX_ATTEMPTS,
                'lease_token' => '',
                'lease_expire_time' => 0,
                'create_time' => time(),
                'update_time' => time(),
                'finish_time' => 0,
                'delete_time' => 0,
            ]);
            $scheduled[] = [
                'id' => $id,
                'run_id' => $childRunId,
                'agent_code' => $agentCode,
                'status' => 'pending',
                'task' => $focus,
            ];
        }
        if ($scheduled === []) {
            throw new Exception('No valid sub-agent task was scheduled');
        }
        AgentTraceLogger::setHandoff($parentRunId, [
            'delivery_mode' => 'durable_parallel',
            'assistant_message_id' => $assistantMessageId,
            'turn_id' => $turnId,
            'iterations' => $iterations,
            'request_id' => $requestId,
        ]);
        return $scheduled;
    }

    /** @return array<int,array<string,mixed>> */
    public static function claim(string $worker, int $leaseSeconds, int $limit): array
    {
        $claimed = [];
        for ($index = 0; $index < max(1, min(20, $limit)); $index++) {
            $row = Db::transaction(function () use ($worker, $leaseSeconds) {
                $now = time();
                $task = Db::name('aigc_canvas_agent_subtask')->where('delete_time', 0)->where(function ($query) use ($now) {
                    $query->where(function ($pending) {
                        $pending->whereIn('status', ['pending', 'retrying']);
                    })->whereOr(function ($expired) use ($now) {
                        $expired->where('status', 'running')->where('lease_expire_time', '<=', $now);
                    });
                })->whereRaw('attempts < max_attempts')->order(['sequence' => 'asc', 'id' => 'asc'])->lock(true)->find();
                if (empty($task)) {
                    return null;
                }
                $token = $worker . '-' . bin2hex(random_bytes(8));
                Db::name('aigc_canvas_agent_subtask')->where('id', (int)$task['id'])->update([
                    'status' => 'running',
                    'attempts' => (int)$task['attempts'] + 1,
                    'lease_token' => $token,
                    'lease_expire_time' => $now + max(30, $leaseSeconds),
                    'update_time' => $now,
                ]);
                $task['lease_token'] = $token;
                $task['attempts'] = (int)$task['attempts'] + 1;
                return $task;
            });
            if ($row === null) {
                break;
            }
            $claimed[] = $row;
        }
        return $claimed;
    }

    public static function execute(array $task): void
    {
        $payload = self::decode($task['payload_json'] ?? []);
        $childRunId = (int)($task['child_run_id'] ?? $payload['child_run_id'] ?? 0);
        try {
            self::assertPayloadIdentity($task, $payload, $childRunId);
            $context = AgentExecutionContext::from([
                'tenant_id' => (int)($payload['tenant_id'] ?? 0),
                'user_id' => (int)($payload['user_id'] ?? 0),
                'project_id' => (int)($payload['project_id'] ?? 0),
                'thread_id' => (int)($payload['thread_id'] ?? 0),
                'message_id' => (int)($payload['assistant_message_id'] ?? 0),
                'user_request' => (string)($payload['request'] ?? ''),
                'canvas_context' => (array)($payload['canvas_context'] ?? []),
            ]);
            if (self::isCanceled($context->messageId())) {
                self::cancel($task);
                AgentTraceLogger::cancelRun($childRunId, ['canceled' => true]);
                self::tryFinalizeParent((int)($task['parent_run_id'] ?? 0));
                return;
            }
            AgentTraceLogger::markRunning($childRunId);
            $result = AgentLlmGateway::call($context, 'sub_' . (string)$payload['agent_code'], self::prompt((string)$payload['agent_code']), [
                'request' => (string)($payload['request'] ?? ''),
                'focus' => (string)($payload['focus'] ?? ''),
                'canvas_context' => (array)($payload['canvas_context'] ?? []),
                'max_tokens' => 1200,
                'request_timeout_seconds' => 120,
                'output_contract' => ['findings' => ['string'], 'recommendation' => 'string'],
            ]);
            $content = trim((string)($result['content'] ?? ''));
            if ($content === '') {
                throw new Exception('Sub-agent returned no usable result');
            }
            $output = ['agent_code' => (string)$payload['agent_code'], 'task' => (string)$payload['focus'], 'result' => $content];
            if (self::isCanceled($context->messageId())) {
                self::cancel($task);
                AgentTraceLogger::cancelRun($childRunId, ['canceled' => true]);
            } else {
                self::succeed($task, $output);
                AgentTraceLogger::finishRun($childRunId, $output);
            }
        } catch (\Throwable $e) {
            $message = mb_substr(trim($e->getMessage()) ?: 'Sub-agent execution failed', 0, 1000, 'UTF-8');
            self::fail($task, $message);
            AgentTraceLogger::failRun($childRunId, $message);
        }
        self::tryFinalizeParent((int)($task['parent_run_id'] ?? 0));
    }

    /**
     * Expired work at its retry limit cannot be claimed again. Mark it terminal
     * so a parent can synthesize remaining sibling results after a worker crash.
     */
    public static function recoverExpiredLeases(): int
    {
        $expired = Db::transaction(function () {
            $now = time();
            $rows = Db::name('aigc_canvas_agent_subtask')->where('delete_time', 0)
                ->where('status', 'running')->where('lease_expire_time', '<=', $now)
                ->whereRaw('attempts >= max_attempts')->lock(true)->select()->toArray();
            foreach ($rows as $row) {
                Db::name('aigc_canvas_agent_subtask')->where([
                    'id' => (int)$row['id'],
                    'status' => 'running',
                    'lease_token' => (string)$row['lease_token'],
                ])->update([
                    'status' => 'failed', 'error' => 'Worker lease expired after maximum attempts',
                    'lease_token' => '', 'lease_expire_time' => 0,
                    'finish_time' => $now, 'update_time' => $now,
                ]);
            }
            return $rows;
        });
        foreach ($expired as $task) {
            AgentTraceLogger::failRun((int)($task['child_run_id'] ?? 0), 'Worker lease expired after maximum attempts');
            self::tryFinalizeParent((int)($task['parent_run_id'] ?? 0));
        }
        return count($expired);
    }

    public static function statusForRun(int $tenantId, int $userId, int $parentRunId): array
    {
        if ($parentRunId <= 0) {
            return [];
        }
        $rows = Db::name('aigc_canvas_agent_subtask')->where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'parent_run_id' => $parentRunId,
            'delete_time' => 0,
        ])->order(['sequence' => 'asc', 'id' => 'asc'])->select()->toArray();
        return array_map(static function (array $row): array {
            return [
                'id' => (int)($row['id'] ?? 0),
                'run_id' => (int)($row['child_run_id'] ?? 0),
                'agent_code' => (string)($row['agent_code'] ?? ''),
                'sequence' => (int)($row['sequence'] ?? 0),
                'status' => (string)($row['status'] ?? ''),
                'error' => (string)($row['error'] ?? ''),
                'created_at' => (int)($row['create_time'] ?? 0),
                'finished_at' => (int)($row['finish_time'] ?? 0),
            ];
        }, $rows);
    }

    public static function cancelByAssistantMessage(int $tenantId, int $userId, int $messageId): void
    {
        if ($messageId <= 0) {
            return;
        }
        $needle = '"assistant_message_id":' . $messageId;
        $query = Db::name('aigc_canvas_agent_subtask')->where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'delete_time' => 0,
        ])->whereLike('payload_json', '%' . $needle . '%')->whereIn('status', ['pending', 'retrying']);
        $tasks = $query->field(['id', 'parent_run_id', 'child_run_id'])->select()->toArray();
        if ($tasks === []) {
            return;
        }
        $parentIds = [];
        foreach ($tasks as $task) {
            $canceled = Db::name('aigc_canvas_agent_subtask')->where('id', (int)$task['id'])
                ->whereIn('status', ['pending', 'retrying'])->update([
                    'status' => 'canceled', 'lease_token' => '', 'lease_expire_time' => 0,
                    'finish_time' => time(), 'update_time' => time(),
                ]);
            if ($canceled !== 1) {
                continue;
            }
            $parentIds[] = (int)$task['parent_run_id'];
            AgentTraceLogger::cancelRun((int)$task['child_run_id'], ['canceled' => true]);
        }
        foreach (array_values(array_unique($parentIds)) as $parentRunId) {
            self::tryFinalizeParent($parentRunId);
        }
    }

    public static function summaryForRun(int $tenantId, int $userId, int $parentRunId): array
    {
        $summary = ['total' => 0, 'pending' => 0, 'running' => 0, 'success' => 0, 'failed' => 0, 'canceled' => 0];
        foreach (self::statusForRun($tenantId, $userId, $parentRunId) as $task) {
            $summary['total']++;
            $status = (string)($task['status'] ?? '');
            if (array_key_exists($status, $summary)) {
                $summary[$status]++;
            }
        }
        return $summary;
    }

    private static function succeed(array $task, array $output): void
    {
        Db::name('aigc_canvas_agent_subtask')->where([
            'id' => (int)$task['id'],
            'lease_token' => (string)$task['lease_token'],
        ])->update([
            'status' => 'success', 'result_json' => self::json($output), 'error' => '',
            'lease_token' => '', 'lease_expire_time' => 0, 'finish_time' => time(), 'update_time' => time(),
        ]);
    }

    private static function fail(array $task, string $error): void
    {
        $retry = (int)($task['attempts'] ?? 0) < max(1, (int)($task['max_attempts'] ?? self::MAX_ATTEMPTS));
        Db::name('aigc_canvas_agent_subtask')->where([
            'id' => (int)$task['id'],
            'lease_token' => (string)$task['lease_token'],
        ])->update([
            'status' => $retry ? 'retrying' : 'failed', 'error' => $error,
            'lease_token' => '', 'lease_expire_time' => 0,
            'finish_time' => $retry ? 0 : time(), 'update_time' => time(),
        ]);
    }

    private static function cancel(array $task): void
    {
        Db::name('aigc_canvas_agent_subtask')->where([
            'id' => (int)$task['id'],
            'lease_token' => (string)$task['lease_token'],
        ])->update([
            'status' => 'canceled', 'lease_token' => '', 'lease_expire_time' => 0,
            'finish_time' => time(), 'update_time' => time(),
        ]);
    }

    private static function tryFinalizeParent(int $parentRunId): void
    {
        if ($parentRunId <= 0) {
            return;
        }
        $tasks = Db::name('aigc_canvas_agent_subtask')->where(['parent_run_id' => $parentRunId, 'delete_time' => 0])->select()->toArray();
        if ($tasks === [] || array_filter($tasks, static fn(array $item): bool => !in_array((string)$item['status'], ['success', 'failed', 'canceled'], true))) {
            return;
        }
        $firstPayload = self::decode($tasks[0]['payload_json'] ?? []);
        $assistantMessageId = (int)($firstPayload['assistant_message_id'] ?? 0);
        if (self::isCanceled($assistantMessageId)) {
            $parent = Db::name('aigc_canvas_agent_run')->where('id', $parentRunId)->find();
            $handoff = self::decode($parent['handoff_json'] ?? []);
            AgentTraceLogger::cancelRun($parentRunId, ['canceled' => true]);
            AgentTurnTraceService::cancel((int)($handoff['turn_id'] ?? 0), (int)($handoff['iterations'] ?? 0), ['canceled' => true]);
            return;
        }
        $locked = Db::name('aigc_canvas_agent_run')->where('id', $parentRunId)->whereIn('status', ['running', 'pending'])->update([
            'status' => 'aggregating', 'update_time' => time(),
        ]);
        if ($locked !== 1) {
            return;
        }
        $parent = Db::name('aigc_canvas_agent_run')->where('id', $parentRunId)->find();
        $handoff = self::decode($parent['handoff_json'] ?? []);
        $first = self::decode($tasks[0]['payload_json'] ?? []);
        $context = AgentExecutionContext::from([
            'tenant_id' => (int)($first['tenant_id'] ?? 0), 'user_id' => (int)($first['user_id'] ?? 0),
            'project_id' => (int)($first['project_id'] ?? 0), 'thread_id' => (int)($first['thread_id'] ?? 0),
            'message_id' => (int)($handoff['assistant_message_id'] ?? $first['assistant_message_id'] ?? 0),
            'user_request' => (string)($first['request'] ?? ''), 'canvas_context' => (array)($first['canvas_context'] ?? []),
        ]);
        $subtasks = array_map(static function (array $row): array {
            $result = self::decode($row['result_json'] ?? []);
            return [
                'agent_code' => (string)$row['agent_code'], 'status' => (string)$row['status'],
                'task' => (string)($result['task'] ?? ''), 'result' => (string)($result['result'] ?? ''),
                'error' => (string)($row['error'] ?? ''),
            ];
        }, $tasks);
        try {
            $response = AgentLlmGateway::call($context, 'subtask_synthesis', self::synthesisPrompt(), [
                'request' => (string)($first['request'] ?? ''), 'canvas_context' => (array)($first['canvas_context'] ?? []),
                'subtasks' => $subtasks, 'max_tokens' => 1200, 'request_timeout_seconds' => 120,
            ]);
            $reply = trim((string)($response['content'] ?? ''));
            if ($reply === '') {
                $reply = self::fallbackReply($subtasks);
            }
            // Delegate synthesis is model output too. It can contain the
            // orchestration prompt, tool contract or scratchpad, none of
            // which belongs in the saved conversation.
            $reply = AgentResponseProtocol::userFacingReply([
                'reply' => $reply,
                'next_action' => 'chat',
                'task_decision' => ['intent' => 'text_generation'],
            ]);
            $assistant = AigcCanvasAgentMessage::findOrEmpty((int)$context->messageId());
            if ($assistant->isEmpty()) {
                throw new Exception('Deferred assistant message was not found');
            }
            $contentJson = is_array($assistant['content_json'] ?? null) ? $assistant['content_json'] : [];
            $contentJson['subtasks'] = $subtasks;
            $contentJson['next_action'] = 'chat';
            $contentJson['agent_trace'] = array_merge((array)($contentJson['agent_trace'] ?? []), ['subtask_mode' => 'durable_parallel']);
            $contentJson['response'] = AgentResponseProtocol::fromResult([
                'reply' => $reply,
                'next_action' => 'chat',
                'task_decision' => ['intent' => 'text_generation'],
            ]);
            $assistant->save(['content' => $reply, 'content_json' => $contentJson, 'status' => 'success', 'update_time' => time()]);
            AigcCanvasAgentThread::where('id', $context->threadId())->update(['update_time' => time()]);
            $thread = AigcCanvasAgentThread::where('id', $context->threadId())->findOrEmpty();
            $payload = [
                'thread' => $thread->isEmpty() ? [] : AigcCanvasAgentRuntimeService::formatThread($thread->toArray()),
                'assistant_message' => AigcCanvasAgentRuntimeService::formatMessage($assistant->toArray()),
                'tool_calls' => [], 'workspace_actions' => [], 'assets' => [], 'next_action' => 'chat',
                'intent' => 'agent_loop', 'skill_key' => '', 'run_id' => $parentRunId,
                'turn_id' => (int)($handoff['turn_id'] ?? 0), 'request_id' => (string)($first['request_id'] ?? ''),
                'iterations' => (int)($handoff['iterations'] ?? 0), 'subtasks' => $subtasks,
            ];
            AgentTraceLogger::finishRun($parentRunId, $payload);
            AgentTurnTraceService::event((int)($handoff['turn_id'] ?? 0), 999, 'subagents.completed', ['subtask_count' => count($subtasks)]);
            AgentTurnTraceService::complete((int)($handoff['turn_id'] ?? 0), (int)($handoff['iterations'] ?? 0), $payload);
        } catch (\Throwable $e) {
            $error = mb_substr(trim($e->getMessage()) ?: 'Sub-agent synthesis failed', 0, 1000, 'UTF-8');
            AgentTraceLogger::failRun($parentRunId, $error);
            AgentTurnTraceService::fail((int)($handoff['turn_id'] ?? 0), (int)($handoff['iterations'] ?? 0), $error);
            $messageId = (int)($handoff['assistant_message_id'] ?? 0);
            if ($messageId > 0) {
                AigcCanvasAgentMessage::where('id', $messageId)->update(['status' => 'failed', 'error' => $error, 'update_time' => time()]);
            }
        }
    }

    private static function prompt(string $agentCode): string
    {
        return 'You are the ' . $agentCode . ' delegate in a complex canvas task. Complete only the assigned task. Return concise, actionable findings for the parent agent. Do not call media models or modify the canvas.';
    }

    private static function synthesisPrompt(): string
    {
        return 'You are the primary canvas agent. Synthesize completed delegate results into one clear user-facing delivery. Preserve successful partial results, disclose only a short recoverable issue for failed delegates, and do not expose internal task ids, SKUs, providers, or implementation details.';
    }

    private static function fallbackReply(array $subtasks): string
    {
        $success = count(array_filter($subtasks, static fn(array $task): bool => $task['status'] === 'success'));
        return $success > 0 ? 'The delegated analysis is complete. I have consolidated the usable results into the next canvas-ready recommendation.' : 'The delegated analysis could not complete. Please refine the request and try again.';
    }

    private static function isCanceled(int $messageId): bool
    {
        return $messageId > 0
            && (string)(AigcCanvasAgentMessage::where('id', $messageId)->value('status') ?? '') === 'canceled';
    }

    private static function assertPayloadIdentity(array $task, array $payload, int $childRunId): void
    {
        foreach (['tenant_id', 'user_id', 'project_id', 'thread_id'] as $field) {
            if ((int)($task[$field] ?? 0) <= 0 || (int)($task[$field] ?? 0) !== (int)($payload[$field] ?? 0)) {
                throw new Exception('Sub-agent payload identity is invalid');
            }
        }
        if ($childRunId <= 0 || $childRunId !== (int)($payload['child_run_id'] ?? 0)) {
            throw new Exception('Sub-agent child run identity is invalid');
        }
        if (!in_array((string)($payload['agent_code'] ?? ''), ['planner', 'copy', 'visual', 'canvas'], true)
            || trim((string)($payload['focus'] ?? '')) === '') {
            throw new Exception('Sub-agent payload is invalid');
        }
    }

    private static function decode($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = is_string($value) ? json_decode($value, true) : [];
        return is_array($decoded) ? $decoded : [];
    }

    private static function json($value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
