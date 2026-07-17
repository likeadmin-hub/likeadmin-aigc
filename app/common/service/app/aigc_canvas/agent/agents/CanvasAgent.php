<?php

namespace app\common\service\app\aigc_canvas\agent\agents;

use app\common\service\app\aigc_canvas\agent\contracts\AgentInterface;
use app\common\service\app\aigc_canvas\agent\contracts\CanvasProtocol;
use app\common\service\app\aigc_canvas\agent\orchestrator\AgentExecutionContext;

final class CanvasAgent implements AgentInterface
{
    public function code(): string
    {
        return 'canvas';
    }

    public function run(AgentExecutionContext $context): array
    {
        return [
            'summary' => '已将设计结果转换为 JSON Canvas 协议。',
            'canvas_json' => CanvasProtocol::document($context->canvasActions(), [
                'project_id' => $context->projectId(),
                'request_id' => (string)($context->route()['request_id'] ?? ''),
                'skill_key' => (string)($context->route()['skill_key'] ?? ''),
            ]),
        ];
    }
}
