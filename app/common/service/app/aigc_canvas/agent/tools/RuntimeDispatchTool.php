<?php

namespace app\common\service\app\aigc_canvas\agent\tools;

use app\common\service\app\aigc_canvas\agent\contracts\ToolInterface;
use app\common\service\app\aigc_canvas\agent\generation\CanvasGenerationTaskCenterService;
use app\common\service\app\aigc_canvas\agent\generation\CanvasGenerationWorkspaceActionService;
use app\common\service\app\aigc_canvas\agent\orchestrator\AgentExecutionContext;

final class RuntimeDispatchTool implements ToolInterface
{
    private string $code;
    private array $schema;

    public function __construct(string $code, array $schema)
    {
        $this->code = $code;
        $this->schema = $schema;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function schema(): array
    {
        return $this->schema;
    }

    public function execute(AgentExecutionContext $context, array $arguments): array
    {
        $input = array_merge($context->toolOptions($this->code), $arguments, [
            'request_id' => (string)($context->route()['request_id'] ?? ''),
        ]);
        if ($this->code === 'generate_music') {
            $task = CanvasGenerationTaskCenterService::create($context->tenantId(), $context->userId(), $input + ['type' => 'music']);
            $assets = array_values(array_filter((array)($task['result_assets'] ?? []), 'is_array'));
            if ($assets === []) {
                $assets[] = ['type' => 'audio', 'task_id' => (string)($task['task_id'] ?? ''), 'pending' => true];
            }
            $actions = array_map(
                fn(array $asset): array => CanvasGenerationWorkspaceActionService::create($context, $this->code, (string)($input['prompt'] ?? $input['content'] ?? ''), $task, $asset),
                $assets
            );
            return [
                'tool_calls' => [['tool_code' => $this->code, 'status' => (string)($task['status'] ?? 'running'), 'output' => $task]],
                'workspace_actions' => $actions,
                'assets' => $assets,
            ];
        }
        $execution = CanvasAgentToolExecutor::execute(
            $context->tenantId(),
            $context->userId(),
            $this->code,
            $input
        );
        return (array)($execution['output'] ?? []);
    }
}
