<?php

namespace app\common\service\app\aigc_canvas\agent\tools;

use app\common\service\app\aigc_canvas\agent\contracts\CanvasProtocol;
use app\common\service\app\aigc_canvas\agent\contracts\FunctionCallSchema;
use app\common\service\app\aigc_canvas\agent\contracts\ToolInterface;
use app\common\service\app\aigc_canvas\agent\orchestrator\AgentExecutionContext;

final class UpdateElementTool implements ToolInterface
{
    public function code(): string
    {
        return 'update_element';
    }

    public function schema(): array
    {
        return FunctionCallSchema::function($this->code(), 'Update an existing canvas element.', [
            'element_id' => ['type' => 'string'],
            'patch' => ['type' => 'object'],
        ], ['element_id', 'patch']);
    }

    public function execute(AgentExecutionContext $context, array $arguments): array
    {
        return [
            'canvas_actions' => [[
                'type' => CanvasProtocol::UPDATE_ELEMENT,
                'element_id' => (string)($arguments['element_id'] ?? ''),
                'patch' => is_array($arguments['patch'] ?? null) ? $arguments['patch'] : [],
            ]],
        ];
    }
}
