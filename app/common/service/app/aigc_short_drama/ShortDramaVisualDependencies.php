<?php

namespace app\common\service\app\aigc_short_drama;

/** Read-only provenance. Never invalidates, deletes or reselects an existing asset. */
final class ShortDramaVisualDependencies
{
    public static function catalog(array $plan): array
    {
        $catalog = ['version' => 1, 'subjects' => [], 'scenes' => []];
        foreach (['subjects' => $plan['subjects'] ?? [], 'scenes' => $plan['locations'] ?? $plan['scenes'] ?? []] as $kind => $items) {
            foreach ((array)$items as $item) {
                if (!is_array($item) || trim((string)($item['id'] ?? '')) === '') continue;
                // Names, model choices and signed display URLs are not visual edits.
                $appearance = [];
                foreach (['description', 'visual_prompt', 'image_prompt', 'appearance_lock', 'space_lock', 'unified_prompt'] as $key) {
                    $appearance[$key] = str_replace("\r\n", "\n", trim((string)($item[$key] ?? '')));
                }
                $appearance['image'] = self::imageIdentity(($item['raw_image'] ?? '') ?: ($item['image'] ?? ''));
                $appearance['three_view'] = self::imageIdentity(($item['three_view_raw_image'] ?? '') ?: ($item['three_view_image'] ?? ''));
                $catalog[$kind][(string)$item['id']] = [
                    'name' => (string)($item['name'] ?? ''),
                    'fingerprint' => hash('sha256', json_encode($appearance, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                ];
            }
        }
        return $catalog;
    }

    public static function capture(array $plan, array $shot, array $params = [], array $references = []): array
    {
        $catalog = self::catalog($plan);
        $snapshot = ['version' => 1, 'subjects' => [], 'scenes' => [], 'unknown' => false];
        $nested = (array)($params['params'] ?? []);
        $ids = [
            'subjects' => array_merge(self::ids($shot['subject_ref_ids'] ?? []), self::ids($params['selected_subject_ids'] ?? []), self::ids($nested['selected_subject_ids'] ?? [])),
            'scenes' => array_merge(self::ids($shot['scene_ref_id'] ?? $shot['scene_ref'] ?? $shot['location_id'] ?? ''), self::ids($params['selected_scene_ids'] ?? []), self::ids($nested['selected_scene_ids'] ?? [])),
        ];
        foreach ($references as $asset) {
            $meta = (array)($asset['meta'] ?? []);
            $kind = in_array($asset['asset_type'] ?? '', ['subject_image', 'three_view'], true) ? 'subjects' : (($asset['asset_type'] ?? '') === 'scene_image' ? 'scenes' : '');
            if ($kind !== '') $ids[$kind] = array_merge($ids[$kind], self::ids($meta[$kind === 'subjects' ? 'subject_id' : 'scene_id'] ?? $meta['item_id'] ?? ''));
        }
        if (!$ids['scenes'] && trim((string)($shot['scene_name'] ?? '')) !== '') $ids['scenes'][] = trim((string)$shot['scene_name']);
        foreach ($ids as $kind => $tokens) {
            foreach (array_unique($tokens) as $token) {
                $id = $token;
                if (!isset($catalog[$kind][$id])) {
                    $matches = array_keys(array_filter($catalog[$kind], static fn($item) => $item['name'] === $token));
                    if (count($matches) !== 1) { $snapshot['unknown'] = true; continue; }
                    $id = (string)$matches[0];
                }
                $snapshot[$kind][$id] = ['name' => $catalog[$kind][$id]['name'], 'fingerprints' => [$catalog[$kind][$id]['fingerprint']]];
            }
        }
        return $snapshot;
    }

    /** Keep upstream baselines: rendering a video from an old image must not erase its age. */
    public static function inherit(array $snapshot, ?array $upstream): array
    {
        if (($upstream['version'] ?? null) !== 1) { $snapshot['unknown'] = true; return $snapshot; }
        $snapshot['unknown'] = !empty($snapshot['unknown']) || !empty($upstream['unknown']);
        foreach (['subjects', 'scenes'] as $kind) {
            foreach ((array)($upstream[$kind] ?? []) as $id => $dependency) {
                $current = $snapshot[$kind][$id] ?? ['name' => $dependency['name'] ?? '', 'fingerprints' => []];
                $current['fingerprints'] = array_values(array_unique(array_merge($current['fingerprints'], (array)($dependency['fingerprints'] ?? []))));
                $snapshot[$kind][$id] = $current;
            }
        }
        return $snapshot;
    }

    private static function ids($value): array
    {
        return array_values(array_filter(array_map(static fn($id) => is_scalar($id) ? trim((string)$id) : '', is_array($value) ? $value : [$value]), static fn($id) => $id !== ''));
    }

    private static function imageIdentity($value): string
    {
        $value = trim((string)$value);
        // Only strip known expiring authorization parameters; preserve image transformations.
        if (!preg_match('~^https?://~i', $value)) return $value;
        $parts = parse_url($value);
        if (!$parts) return $value;
        parse_str($parts['query'] ?? '', $query);
        foreach (array_keys($query) as $key) {
            if (preg_match('/^(x-amz-|x-oss-|signature$|expires$|ossaccesskeyid$)/i', $key)) unset($query[$key]);
        }
        ksort($query);
        return preg_replace('/[?#].*$/', '', $value) . ($query ? '?' . http_build_query($query) : '');
    }
}
