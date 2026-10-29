<?php

declare(strict_types=1);

namespace app\common\service\ai;

use think\facade\Db;

/** Public task identifiers only. Call after parent task ownership checks. */
class UserTaskTraceService
{
    public static function appendMapped(array $row): array
    {
        return array_key_exists('upstream_task_ids', $row) ? $row : self::appendRows([$row])[0];
    }

    /** Batch per tenant and media table; never one lookup per displayed task. */
    public static function appendRows(array $rows): array
    {
        $groups = [];
        foreach ($rows as &$row) {
            $row['upstream_task_ids'] = [];
            $tenant = (int)($row['tenant_id'] ?? 0);
            $user = (int)($row['user_id'] ?? 0);
            if ($tenant <= 0 || $user <= 0) continue;
            foreach (self::mappedIds($row) as $table => $ids) {
                foreach ($ids as $id) {
                    $groups[$tenant][$table]['ids'][$id] = $id;
                    $groups[$tenant][$table]['users'][$user] = $user;
                }
            }
        }
        unset($row);
        $resolved = [];
        foreach ($groups as $tenant => $tables) {
            foreach ($tables as $table => $group) {
                $children = Db::name($table)->where('tenant_id', $tenant)
                    ->whereIn('user_id', array_values($group['users']))->where('delete_time', 0)
                    ->whereIn('id', array_values($group['ids']))->field('id,user_id,provider_task_id')->select()->toArray();
                foreach ($children as $child) {
                    $resolved[$tenant][$table][(int)$child['user_id']][(int)$child['id']] = trim((string)$child['provider_task_id']);
                }
            }
        }
        foreach ($rows as &$row) {
            $tenant = (int)($row['tenant_id'] ?? 0);
            $user = (int)($row['user_id'] ?? 0);
            foreach (self::mappedIds($row) as $table => $ids) {
                foreach ($ids as $id) {
                    // Match both owners again: a foreign child ID must never
                    // become visible through a malformed business mapping.
                    $value = $resolved[$tenant][$table][$user][$id] ?? '';
                    if ($value !== '' && !in_array($value, $row['upstream_task_ids'], true)) $row['upstream_task_ids'][] = $value;
                }
            }
        }
        unset($row);
        return $rows;
    }

    private static function mappedIds(array $row): array
    {
        $result = $row['result_json'] ?? [];
        if (is_string($result)) $result = json_decode($result, true) ?: [];
        $result = is_array($result) ? $result : [];
        $images = $row['image_task_ids'] ?? [];
        if (is_string($images)) $images = json_decode($images, true) ?: [];
        $images = is_array($images) ? $images : [];
        $images[] = $row['image_task_id'] ?? $result['image_task_id'] ?? 0;
        $positive = static fn (array $ids) => array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($id) => $id > 0)));
        return ['aigc_image_task' => $positive($images), 'aigc_video_task' => $positive([$row['video_task_id'] ?? 0])];
    }
}
