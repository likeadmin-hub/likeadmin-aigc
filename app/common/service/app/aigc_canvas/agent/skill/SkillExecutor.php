<?php

namespace app\common\service\app\aigc_canvas\agent\skill;

use app\common\service\app\aigc_canvas\agent\orchestrator\DesignAgentOrchestrator;

final class SkillExecutor
{
    public static function runDesignSkill(int $tenantId, int $userId, int $projectId, int $threadId, int $messageId, string $content, array $context, array $route, ?callable $emit = null): array
    {
        return (new DesignAgentOrchestrator())->run($tenantId, $userId, $projectId, $threadId, $messageId, $content, $context, $route, $emit);
    }
}
