<?php

namespace app\common\service\app\aigc_canvas\agent\tools;

use app\common\service\app\aigc_canvas\agent\contracts\CanvasProtocol;
use app\common\service\app\aigc_canvas\agent\contracts\FunctionCallSchema;
use app\common\service\app\aigc_canvas\agent\contracts\ToolInterface;
use app\common\service\app\aigc_canvas\agent\orchestrator\AgentExecutionContext;

final class CreatePageTool implements ToolInterface
{
    public function code(): string
    {
        return 'create_page';
    }

    public function schema(): array
    {
        return FunctionCallSchema::function($this->code(), 'Create a canvas page or artboard.', [
            'page_id' => ['type' => 'string'],
            'title' => ['type' => 'string'],
            'width' => ['type' => 'number'],
            'height' => ['type' => 'number'],
            'background' => ['type' => 'string'],
        ], ['page_id', 'title', 'width', 'height']);
    }

    public function execute(AgentExecutionContext $context, array $arguments): array
    {
        return [
            'canvas_actions' => [[
                'type' => CanvasProtocol::CREATE_PAGE,
                'page' => [
                    'id' => (string)($arguments['page_id'] ?? 'page_1'),
                    'title' => (string)($arguments['title'] ?? 'AI Design Page'),
                    'width' => max(320, (float)($arguments['width'] ?? 1440)),
                    'height' => max(320, (float)($arguments['height'] ?? 900)),
                    'background' => (string)($arguments['background'] ?? '#ffffff'),
                ],
            ]],
        ];
    }
}
