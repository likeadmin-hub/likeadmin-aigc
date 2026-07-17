<?php

namespace app\common\service\app\aigc_canvas\agent\tools;

use app\common\service\app\aigc_canvas\agent\contracts\CanvasProtocol;
use app\common\service\app\aigc_canvas\agent\contracts\FunctionCallSchema;
use app\common\service\app\aigc_canvas\agent\contracts\ToolInterface;
use app\common\service\app\aigc_canvas\agent\orchestrator\AgentExecutionContext;

final class AddElementTool implements ToolInterface
{
    public function code(): string
    {
        return 'add_element';
    }

    public function schema(): array
    {
        return FunctionCallSchema::function($this->code(), 'Add an element to JSON Canvas.', [
            'page_id' => ['type' => 'string'],
            'element' => ['type' => 'object'],
        ], ['page_id', 'element']);
    }

    public function execute(AgentExecutionContext $context, array $arguments): array
    {
        $element = is_array($arguments['element'] ?? null) ? $arguments['element'] : [];
        $element['id'] = (string)($element['id'] ?? ('el_' . substr(md5(json_encode($element)), 0, 8)));
        $element['type'] = $this->normalizeType((string)($element['type'] ?? 'text'));
        $element['x'] = (float)($element['x'] ?? 80);
        $element['y'] = (float)($element['y'] ?? 80);
        $element['width'] = max(80, (float)($element['width'] ?? 420));
        $element['height'] = max(60, (float)($element['height'] ?? 160));

        return [
            'canvas_actions' => [[
                'type' => CanvasProtocol::ADD_ELEMENT,
                'page_id' => (string)($arguments['page_id'] ?? 'page_1'),
                'element' => $element,
            ]],
        ];
    }

    private function normalizeType(string $type): string
    {
        return in_array($type, ['text', 'image', 'video', 'audio', 'shape', 'group'], true) ? $type : 'text';
    }
}
