<?php

namespace app\common\service\app\aigc_canvas\agent\memory;

use think\facade\Db;

final class ProjectMemoryService
{
    public static function load(int $tenantId, int $userId, int $projectId, bool $includeConversationGoals = true): array
    {
        if ($tenantId <= 0 || $userId <= 0 || $projectId <= 0) {
            return [];
        }
        $rows = Db::name('aigc_canvas_agent_memory')
            ->where([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'project_id' => $projectId,
                'delete_time' => 0,
            ])
            ->order(['update_time' => 'desc', 'id' => 'desc'])
            ->select()
            ->toArray();
        $memory = [];
        foreach ($rows as $row) {
            if (!$includeConversationGoals && (string)($row['memory_type'] ?? '') === 'project_goal') {
                continue;
            }
            $source = json_decode((string)($row['source_json'] ?? ''), true);
            if (!self::isPersistentReferenceAsset((string)($row['memory_type'] ?? ''), is_array($source) ? $source : [])) {
                continue;
            }
            $key = (string)($row['memory_key'] ?? '');
            if ($key === '') {
                continue;
            }
            $json = json_decode((string)($row['memory_json'] ?? ''), true);
            $memory[$key] = [
                'type' => (string)($row['memory_type'] ?? 'project'),
                'summary' => (string)($row['summary'] ?? ''),
                'data' => is_array($json) ? $json : [],
                'version' => (int)($row['version'] ?? 1),
                'source' => is_array($source) ? $source : [],
            ];
        }
        return $memory;
    }

    private static function isPersistentReferenceAsset(string $type, array $source): bool
    {
        return $type !== 'reference_asset' || (string)($source['scope'] ?? '') === 'canvas';
    }

    public static function retrieve(int $tenantId, int $userId, int $projectId, int $limit = 8, bool $includeConversationGoals = true): array
    {
        $all = self::load($tenantId, $userId, $projectId, $includeConversationGoals);
        return array_slice($all, 0, max(1, min(8, $limit)), true);
    }

    public static function remember(int $tenantId, int $userId, int $projectId, string $type, string $key, array $data, array $source = []): void
    {
        if ($tenantId <= 0 || $userId <= 0 || $projectId <= 0 || $key === '') {
            return;
        }
        $now = time();
        $exists = Db::name('aigc_canvas_agent_memory')
            ->where([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'project_id' => $projectId,
                'memory_type' => $type,
                'memory_key' => $key,
                'delete_time' => 0,
            ])
            ->find();
        $data = [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => $projectId,
            'memory_type' => $type,
            'memory_key' => $key,
            'memory_json' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'summary' => mb_substr((string)($data['summary'] ?? $data['value'] ?? ''), 0, 1000, 'UTF-8'),
            'source_json' => json_encode($source, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'version' => max(1, (int)($exists['version'] ?? 0) + 1),
            'update_time' => $now,
        ];
        if (!empty($exists)) {
            Db::name('aigc_canvas_agent_memory')->where('id', (int)$exists['id'])->update($data);
            return;
        }
        $data['create_time'] = $now;
        $data['delete_time'] = 0;
        Db::name('aigc_canvas_agent_memory')->insert($data);
    }

    public static function rememberRun(int $tenantId, int $userId, int $projectId, array $summary): void
    {
        self::remember($tenantId, $userId, $projectId, 'project_goal', 'design_brief', $summary, (array)($summary['source'] ?? []));
    }

    public static function ensureSchema(): void
    {
        // Schema ownership belongs to app migrations.
    }
}
