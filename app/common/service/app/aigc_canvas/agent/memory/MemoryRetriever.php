<?php

namespace app\common\service\app\aigc_canvas\agent\memory;

final class MemoryRetriever
{
    public static function retrieve(int $tenantId, int $userId, int $projectId, int $limit = 8, bool $includeConversationGoals = true): array
    {
        return ProjectMemoryService::retrieve($tenantId, $userId, $projectId, $limit, $includeConversationGoals);
    }
}
