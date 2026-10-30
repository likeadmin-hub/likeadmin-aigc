<?php
namespace app\common\service\app\aigc_short_drama;

use RuntimeException;

/** Lossless adapter for raw and previously normalized parser receipts. */
final class ShortDramaImportedScript
{
    /**
     * Imported shot lists often number whole seconds as 0-3, 4-8, 9-13.
     * Interpret that consistent notation as consecutive editorial ranges,
     * retaining the first/last boundary and the original labels for audit.
     * Exact timestamps, fractions, mixed conventions and real gaps stay strict.
     */
    public static function normalizeTimeline(array $segments): array
    {
        if (count($segments) < 2) return $segments;
        $previousEnd = null;
        foreach ($segments as $segment) {
            $start = (float)($segment['start_seconds'] ?? -1);
            $end = (float)($segment['end_seconds'] ?? -1);
            if (!is_finite($start) || !is_finite($end) || $start < 0 || $end <= $start
                || floor($start) !== $start || floor($end) !== $end
                || !preg_match('/^[0-9０-９]+\s*(?:秒|s|sec(?:ond)?s?)?\s*(?:--?>|→|[-－‐‑‒–—−~～至到])\s*[0-9０-９]+\s*(?:秒|s|sec(?:ond)?s?)$/iu', trim((string)($segment['time_range'] ?? '')))
                || ($previousEnd !== null && abs($start - $previousEnd - 1) > 0.001)) {
                return $segments;
            }
            $previousEnd = $end;
        }
        $segments = array_values($segments);
        foreach ($segments as $index => &$segment) {
            if ($index === 0) continue;
            $segment['source_time_range'] = $segment['time_range'];
            $segment['source_start_seconds'] = $segment['start_seconds'];
            $segment['start_seconds'] = $segments[$index - 1]['end_seconds'];
            $segment['duration_seconds'] = $segment['end_seconds'] - $segment['start_seconds'];
            $segment['time_range'] = $segment['start_seconds'] . '-' . $segment['end_seconds'] . '秒';
        }
        unset($segment);
        return $segments;
    }

    public static function storyDraft(array $outline): array
    {
        $story = $outline;
        $story['episodes'] = [];
        $story['storyboard'] = [];
        $story['multi_episode_stage'] = 'story';
        $story['parse_metadata']['imported_story'] = true;
        unset($story['series_bible']['episode_summaries']);
        return $story;
    }

    public static function confirmedOutline(array $outline, array $story): array
    {
        if ((int)($story['episode_count'] ?? 0) !== count($outline['episodes'] ?? [])) {
            throw new RuntimeException('原文分集数量已变化，请通过 AI 修改重新规划；解析原文已保留', 422);
        }
        foreach (['title', 'type_judgement', 'core_theme', 'story_outline', 'series_bible', 'subjects', 'locations'] as $key) {
            if (array_key_exists($key, $story)) $outline[$key] = $story[$key];
        }
        $outline['episodes'] = ShortDramaSourceBoundary::episodes($outline['episodes'] ?? []);
        $outline['multi_episode_stage'] = 'episodes';
        $outline['storyboard'] = [];
        return $outline;
    }

    public static function sourceContent(array $episode): string
    {
        $values = [];
        foreach (['content', 'source_content'] as $key) {
            if (!array_key_exists($key, $episode) || $episode[$key] === null) continue;
            if (!is_string($episode[$key])) throw new RuntimeException('解析结果的剧本原文必须为文本，原始回包已保留', 422);
            if ($episode[$key] !== '') $values[$key] = $episode[$key];
        }
        if (count($values) === 2 && $values['content'] !== $values['source_content']) {
            throw new RuntimeException('解析结果包含不一致的两份剧本原文，请核对原始回包', 422);
        }
        return $values['content'] ?? $values['source_content'] ?? '';
    }
}
