<?php

namespace app\common\service\app\aigc_canvas\agent\memory;

final class AgentMemoryRepository
{
    public function loadProjectMemory(int $tenantId, int $projectId): array
    {
        return ProjectMemoryService::load($tenantId, $projectId);
    }

    public function saveProjectSummary(int $tenantId, int $userId, int $projectId, array $summary): void
    {
        ProjectMemoryService::rememberRun($tenantId, $userId, $projectId, $summary);
    }
}
