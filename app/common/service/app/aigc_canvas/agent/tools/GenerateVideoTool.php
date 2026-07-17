<?php

namespace app\common\service\app\aigc_canvas\agent\tools;

use app\common\service\app\aigc_canvas\AigcCanvasAgentRuntimeService;
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
        return AigcCanvasAgentRuntimeService::executeExternalToolWithActions(
            $context->tenantId(),
            $context->userId(),
            $context->projectId(),
            $context->threadId(),
            $context->messageId(),
            $this->code(),
            $input,
            $input['prompt'],
            $context->context(),
            $context->emit(),
            1
        );
    }
}
