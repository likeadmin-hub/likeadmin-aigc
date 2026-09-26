<?php
namespace app\common\service\app\aigc_short_drama;

use RuntimeException;

/** Lossless adapter for raw and previously normalized parser receipts. */
final class ShortDramaImportedScript
{
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
