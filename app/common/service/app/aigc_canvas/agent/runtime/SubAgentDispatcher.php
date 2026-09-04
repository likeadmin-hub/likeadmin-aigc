<?php

namespace app\common\service\app\aigc_canvas\agent\runtime;

use app\common\service\app\aigc_canvas\agent\orchestrator\AgentExecutionContext;
use Exception;

/**
 * The primary Agent decides whether delegation is useful. Delegates are queued
 * durably so sibling workers can process complex work in parallel and the
 * parent can be resumed after every sibling reaches a terminal state.
 */
final class SubAgentDispatcher
{
    private const MAX_TASKS = 3;

    public static function dispatch(AgentExecutionContext $context, array $arguments, int $parentRunId, string $requestId, string $request, array $canvasContext, ?callable $emit): array
    {
        $tasks = array_values(array_filter((array)($arguments['tasks'] ?? []), 'is_array'));
        if ($tasks === []) {
            throw new Exception('At least one sub-agent task is required');
        }
        if (count($tasks) > self::MAX_TASKS) {
            throw new Exception('A maximum of ' . self::MAX_TASKS . ' sub-agent tasks can be delegated at once');
        }
        $validated = [];
        foreach ($tasks as $task) {
            $validated[] = SubAgentTaskService::normalizeCreativeWorkUnit($task);
        }
        $scheduled = SubAgentTaskService::dispatch(
            $context,
            $validated,
            $parentRunId,
            (int)($arguments['_turn_id'] ?? 0),
            (int)($arguments['_iterations'] ?? 0),
            $requestId,
            $request,
            $canvasContext,
            $context->messageId()
        );
        foreach ($scheduled as $task) {
            self::emit($emit, 'agent.subagent.started', [
                'agent_code' => $task['agent_code'],
                'work_unit' => $task['work_unit'],
                'run_id' => $task['run_id'],
                'label' => (string)($task['label'] ?? $task['work_unit']),
                'status' => 'pending',
            ]);
        }
        return [
            'tool_calls' => [[
                'tool_code' => 'delegate_subagent',
                'status' => 'pending',
                'input' => ['task_count' => count($scheduled)],
                'output' => ['subtasks' => $scheduled],
            ]],
            'workspace_actions' => [],
            'assets' => [],
            'subtasks' => $scheduled,
            'subtasks_pending' => true,
        ];
    }

    private static function emit(?callable $emit, string $event, array $payload): void
    {
        if (is_callable($emit)) {
            $emit($event, $payload);
        }
    }
}
