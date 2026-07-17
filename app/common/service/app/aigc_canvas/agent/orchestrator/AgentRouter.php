<?php

namespace app\common\service\app\aigc_canvas\agent\orchestrator;

use app\common\service\app\aigc_canvas\agent\agents\CanvasAgent;
use app\common\service\app\aigc_canvas\agent\agents\CopyAgent;
use app\common\service\app\aigc_canvas\agent\agents\MasterAgent;
use app\common\service\app\aigc_canvas\agent\agents\PlannerAgent;
use app\common\service\app\aigc_canvas\agent\agents\VisualAgent;
use app\common\service\app\aigc_canvas\agent\contracts\AgentInterface;
use Exception;

final class AgentRouter
{
    public static function resolve(string $agentCode): AgentInterface
    {
        return match ($agentCode) {
            'master' => new MasterAgent(),
            'planner' => new PlannerAgent(),
            'copy' => new CopyAgent(),
            'visual' => new VisualAgent(),
            'canvas' => new CanvasAgent(),
            default => throw new Exception('Unknown design agent: ' . $agentCode),
        };
    }
}
