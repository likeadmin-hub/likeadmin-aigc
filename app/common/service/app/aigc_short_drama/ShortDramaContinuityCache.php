<?php
namespace app\common\service\app\aigc_short_drama;

use think\facade\Cache;

/** Cache derived checks, never task state. Every hit is fenced by the actual source content. */
final class ShortDramaContinuityCache
{
    public static function resolve(int $tenant, int $user, int $project, array $rows, array $current, bool $review): array
    {
        $inputs = array_map(static fn($row) => array_intersect_key($row,
            array_flip(['id','production_project_id','status','continuity_json'])), $rows);
        $versions = array_map(static fn($version) => array_intersect_key($version,
            array_flip(['ledger','narrative_digest'])), $current);
        $fingerprint = hash('sha256', json_encode([$inputs,$versions,$review], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $key = 'short_drama_continuity_v1:' . $tenant . ':' . $user . ':' . $project;
        try { $cached = Cache::get($key); } catch (\Throwable) { $cached = null; }
        if (is_array($cached) && ($cached['fingerprint'] ?? '') === $fingerprint && count($cached['rows'] ?? []) === count($rows)) {
            foreach ($rows as &$row) $row = array_replace($row, $cached['rows'][(int)$row['id']] ?? []);
            unset($row);
            return $rows;
        }
        $result = ShortDramaContinuity::dependencyStatus($rows, $current, $review);
        $derived = [];
        foreach ($result as $row) $derived[(int)$row['id']] = array_intersect_key($row,
            array_flip(['needs_review','review_reason','continuity_json']));
        try { Cache::set($key, ['fingerprint'=>$fingerprint,'rows'=>$derived], 300); } catch (\Throwable) { /* Cache is optional. */ }
        return $result;
    }
}
