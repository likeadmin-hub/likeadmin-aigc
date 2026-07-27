<?php

namespace app\common\service\app\aigc_canvas\agent\tools;

use app\common\service\app\aigc_canvas\agent\generation\CanvasGenerationTaskCenterService;
use app\common\service\app\aigc_canvas\agent\generation\CanvasGenerationWorkspaceActionService;
use app\common\service\app\aigc_canvas\agent\contracts\FunctionCallSchema;
use app\common\service\app\aigc_canvas\agent\contracts\ToolInterface;
use app\common\service\app\aigc_canvas\agent\orchestrator\AgentExecutionContext;

final class GenerateVideoTool implements ToolInterface
{
    public function code(): string
    {
        return 'generate_video';
    }

    public function schema(): array
    {
        return FunctionCallSchema::function($this->code(), 'Generate video assets using the current canvas video model.', [
            'prompt' => ['type' => 'string'],
            'duration' => ['type' => 'number'],
            'ratio' => ['type' => 'string'],
            'target_element_id' => ['type' => 'string'],
        ], ['prompt']);
    }

    public function execute(AgentExecutionContext $context, array $arguments): array
    {
        $input = array_merge($context->toolOptions($this->code()), [
            'prompt' => trim((string)($arguments['prompt'] ?? $context->request())),
            'user_request' => trim((string)($arguments['prompt'] ?? $context->request())),
            'prompt_mode' => (string)($arguments['prompt_mode'] ?? 'direct'),
            'project_id' => $context->projectId(),
            'request_id' => (string)($context->route()['request_id'] ?? ''),
        ]);
        if (!empty($arguments['duration'])) {
            $input['duration'] = (int)$arguments['duration'];
        }
        if (!empty($arguments['ratio'])) {
            $input['ratio'] = (string)$arguments['ratio'];
        }
        if (!empty($arguments['target_element_id'])) {
            $input['target_element_id'] = (string)$arguments['target_element_id'];
        }
        $input['creative_context'] = (array)($context->context()['creative_context'] ?? $context->context()['enriched_context']['creative_context'] ?? []);
        $task = CanvasGenerationTaskCenterService::create($context->tenantId(), $context->userId(), $input + ['type' => 'video']);
        $assets = array_values(array_filter((array)($task['result_assets'] ?? []), 'is_array'));
        if ($assets === []) {
            $assets[] = ['type' => 'video', 'task_id' => (string)($task['task_id'] ?? ''), 'pending' => true];
        }
        $assets = array_map(static fn(array $asset): array => array_merge($asset, [
            'target_element_id' => (string)($input['target_element_id'] ?? ''),
        ]), $assets);
        $actions = array_map(
            fn(array $asset): array => CanvasGenerationWorkspaceActionService::create($context, $this->code(), (string)($task['compiled_prompt'] ?? $task['submitted_input']['compiled_prompt'] ?? $input['prompt']), $task, $asset),
            $assets
        );
        return [
            'tool_calls' => [[
                'tool_code' => $this->code(),
                'status' => (string)($task['status'] ?? 'running'),
                'output' => $task,
            ]],
            'workspace_actions' => $actions,
            'assets' => $assets,
            'generation_task' => $task,
            'next_action' => 'execute_tool',
        ];
    }
}
