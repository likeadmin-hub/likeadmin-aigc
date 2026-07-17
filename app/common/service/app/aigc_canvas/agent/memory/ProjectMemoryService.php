<?php

namespace app\common\service\app\aigc_canvas\agent\memory;

use think\facade\Db;

final class ProjectMemoryService
{
    public static function load(int $tenantId, int $projectId): array
    {
        if ($tenantId <= 0 || $projectId <= 0) {
            return [];
        }
        $rows = Db::name('aigc_canvas_agent_memory')
            ->where([
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'delete_time' => 0,
            ])
            ->select()
            ->toArray();
        $memory = [];
        foreach ($rows as $row) {
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
                'source' => json_decode((string)($row['source_json'] ?? ''), true) ?: [],
            ];
        }
        return $memory;
    }

    public static function rememberRun(int $tenantId, int $userId, int $projectId, array $summary): void
    {
        if ($tenantId <= 0 || $projectId <= 0) {
            return;
        }
        $key = 'design_brief';
        $now = time();
        $exists = Db::name('aigc_canvas_agent_memory')
            ->where([
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'memory_key' => $key,
                'delete_time' => 0,
            ])
            ->find();
        $data = [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => $projectId,
            'memory_type' => 'project',
            'memory_key' => $key,
            'memory_json' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'summary' => mb_substr((string)($summary['summary'] ?? ''), 0, 1000, 'UTF-8'),
            'source_json' => json_encode((array)($summary['source'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
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

    public static function ensureSchema(): void
    {
        // Schema ownership belongs to app migrations.
    }
}
