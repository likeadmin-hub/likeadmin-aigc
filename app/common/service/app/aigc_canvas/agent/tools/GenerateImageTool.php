<?php

namespace app\common\service\app\aigc_canvas\agent\tools;

use app\common\service\app\aigc_canvas\agent\generation\CanvasGenerationTaskCenterService;
use app\common\service\app\aigc_canvas\agent\generation\CanvasGenerationWorkspaceActionService;
use app\common\service\app\aigc_canvas\agent\contracts\FunctionCallSchema;
use app\common\service\app\aigc_canvas\agent\contracts\ToolInterface;
use app\common\service\app\aigc_canvas\agent\orchestrator\AgentExecutionContext;

final class GenerateImageTool implements ToolInterface
{
    public function code(): string
    {
        return 'generate_image';
    }

    public function schema(): array
    {
        return FunctionCallSchema::function($this->code(), 'Generate image assets using the current canvas image model.', [
            'prompt' => ['type' => 'string'],
            'quantity' => ['type' => 'number'],
            'ratio' => ['type' => 'string'],
            'target_element_id' => ['type' => 'string'],
            'section_key' => ['type' => 'string'],
            'prompt_mode' => ['type' => 'string', 'enum' => ['direct', 'planned', 'edit']],
            'delivery' => ['type' => 'object'],
            'section' => ['type' => 'object'],
            'creative_context' => ['type' => 'object'],
            'original_identity' => ['type' => 'object'],
            'edit_action' => ['type' => 'string'],
        ], ['prompt']);
    }

    public function execute(AgentExecutionContext $context, array $arguments): array
    {
        $input = array_merge($context->toolOptions($this->code()), [
            'prompt' => trim((string)($arguments['prompt'] ?? $context->request())),
            'user_request' => trim((string)($arguments['prompt'] ?? $context->request())),
            'project_id' => $context->projectId(),
            'quantity' => max(1, min(4, (int)($arguments['quantity'] ?? 1))),
            'request_id' => (string)($context->route()['request_id'] ?? ''),
        ]);
        $referenceImages = [];
        $referenceAssets = [];
        foreach ((array)($context->context()['uploaded_references'] ?? []) as $reference) {
            if (!is_array($reference)) {
                continue;
            }
            $url = trim((string)($reference['url'] ?? $reference['uri'] ?? ''));
            if ($url === '') {
                continue;
            }
            $type = strtolower((string)($reference['type'] ?? $reference['asset_type'] ?? 'image'));
            $referenceAssets[] = [
                'type' => $type,
                'url' => $url,
                'uri' => $url,
                'name' => (string)($reference['name'] ?? ''),
            ];
            if ($type === 'image' || $type === 'asset') {
                $referenceImages[] = $url;
            }
        }
        if (!empty($referenceImages)) {
            $input['reference_images'] = array_values(array_unique(array_merge(
                (array)($input['reference_images'] ?? []),
                $referenceImages
            )));
        }
        if (!empty($referenceAssets)) {
            $input['reference_assets'] = $referenceAssets;
        }
        if (!empty($arguments['ratio'])) {
            $input['ratio'] = (string)$arguments['ratio'];
        }
        foreach (['target_element_id', 'section_key'] as $key) {
            if (!empty($arguments[$key])) {
                $input[$key] = (string)$arguments[$key];
            }
        }
        foreach (['prompt_mode', 'delivery', 'section', 'original_identity', 'edit_action'] as $key) {
            if (array_key_exists($key, $arguments)) $input[$key] = $arguments[$key];
        }
        $input['creative_context'] = (array)($arguments['creative_context'] ?? $context->context()['creative_context'] ?? $context->context()['enriched_context']['creative_context'] ?? []);
        $task = CanvasGenerationTaskCenterService::create($context->tenantId(), $context->userId(), $input + ['type' => 'image']);
        return $this->result($context, $task, $input);
    }

    private function result(AgentExecutionContext $context, array $task, array $input): array
    {
        $assets = array_values(array_filter((array)($task['result_assets'] ?? []), 'is_array'));
        if ($assets === []) {
            $assets[] = ['type' => 'image', 'task_id' => (string)($task['task_id'] ?? ''), 'pending' => true];
        }
        $assets = array_map(static fn(array $asset): array => array_merge($asset, [
            'target_element_id' => (string)($input['target_element_id'] ?? ''),
            'section_key' => (string)($input['section_key'] ?? ''),
        ]), $assets);
        $submittedInput = (array)($task['submitted_input'] ?? $input);
        $submittedPrompt = (string)($submittedInput['compiled_prompt'] ?? $submittedInput['prompt'] ?? $input['prompt']);
        $actions = array_map(
            fn(array $asset): array => CanvasGenerationWorkspaceActionService::create($context, $this->code(), $submittedPrompt, $task, $asset, $submittedInput),
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
