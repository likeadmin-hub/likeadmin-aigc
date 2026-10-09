<?php
namespace app\common\service\app\aigc_short_drama\vnext;

use RuntimeException;

/** Source positions never depend on model-generated IDs or a truncated prompt copy. */
final class SourceTrace
{
    public static function capture(string $sourceId, string $version, string $text, string $mode): array
    {
        ContentDocument::text($sourceId, 'source_id'); ContentDocument::text($version, 'source_version'); ContentDocument::text($text, 'source.text');
        if (!in_array($mode, ['inspiration', 'adaptation', 'faithful'], true)) throw new RuntimeException('来源保留策略无效', 422);
        return ['source_id' => $sourceId, 'version' => $version, 'text' => $text, 'hash' => hash('sha256', $text),
            'mode' => $mode, 'offset_unit' => 'unicode_code_point'];
    }

    /** Exact contiguous segmentation for capacity planning. Never a lossy summary. */
    public static function segments(array $source, int $maximumCharacters): array
    {
        if ($maximumCharacters < 1 || ($source['hash'] ?? '') !== hash('sha256', $source['text'] ?? '')) throw new RuntimeException('原文版本或分段容量无效', 422);
        $length = mb_strlen($source['text'], 'UTF-8'); $segments = [];
        for ($start = 0; $start < $length; $start += $maximumCharacters) {
            $end = min($length, $start + $maximumCharacters);
            $segments[] = ['source_id' => $source['source_id'], 'source_version' => $source['version'], 'source_hash' => $source['hash'],
                'start' => $start, 'end' => $end, 'text' => ContentDocument::slice($source['text'], $start, $end)];
        }
        return $segments;
    }

    /** The caller supplies confirmed spans/scope; candidate extraction alone is never confirmation. */
    public static function requirements(array $source, array $spans): array
    {
        if (($source['hash'] ?? '') !== hash('sha256', $source['text'] ?? '')) throw new RuntimeException('原文版本已变化', 409);
        $result = []; $seen = [];
        foreach (ContentDocument::rows($spans, 'requirement.spans') as $span) {
            $text = ContentDocument::slice($source['text'], $span['start'] ?? null, $span['end'] ?? null, $span['quote'] ?? null);
            $key = $span['start'] . ':' . $span['end'];
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $result[] = ['id' => 'requirement_' . substr(ContentDocument::hash([$source['source_id'], $key]), 0, 24),
                'text' => $text, 'label' => $text, 'blocking' => (bool)($span['blocking'] ?? true), 'episode_ids' => $span['episode_ids'] ?? [],
                'preservation' => $source['mode'], 'source_id' => $source['source_id'], 'source_version' => $source['version'],
                'source_hash' => $source['hash'], 'start' => $span['start'], 'end' => $span['end']];
        }
        return $result;
    }

    /** Validate the confirmed outline mapping without treating future episodes as present facts. */
    public static function confirmEvents(string $outlineRevision, array $requirements, array $events, array $confirmedEpisodes): array
    {
        ContentDocument::text($outlineRevision, 'outline_revision');
        $requirementsById = array_column($requirements, null, 'id'); $result = []; $assigned = [];
        foreach (ContentDocument::rows($events, 'events') as $index => $event) {
            $episode = ContentDocument::text($event['episode_id'] ?? null, 'event.episode_id');
            $text = ContentDocument::text($event['text'] ?? null, 'event.text');
            $refs = $event['requirement_ids'] ?? [];
            if (!is_array($refs)) throw new RuntimeException('事件来源引用无效', 422);
            foreach ($refs as $id) {
                if (!isset($requirementsById[$id])) throw new RuntimeException('事件引用未知要求', 422);
                $scope = $requirementsById[$id]['episode_ids'] ?? [];
                if ($scope && !in_array($episode, $scope, true)) throw new RuntimeException('要求被分配到错误集', 422);
                $assigned[$id][] = $episode;
            }
            $result[] = ['id' => 'event_' . substr(ContentDocument::hash([$outlineRevision, $index]), 0, 24),
                'outline_revision' => $outlineRevision, 'text' => $text, 'label' => $text, 'episode_ids' => [$episode],
                'requirement_ids' => array_values(array_unique($refs)), 'blocking' => (bool)($event['blocking'] ?? true),
                'confirmed' => in_array($episode, $confirmedEpisodes, true), 'origin' => $refs ? 'requirement' : 'creative'];
        }
        foreach ($requirements as $requirement) {
            if (empty($requirement['blocking'])) continue;
            $scope = $requirement['episode_ids'] ?? [];
            // Unscoped series requirements await explicit allocation; do not pretend a partial outline proves coverage.
            $relevant = $scope ? array_intersect($scope, $confirmedEpisodes) : [];
            if ($relevant && !array_intersect($relevant, $assigned[$requirement['id']] ?? [])) throw new RuntimeException('已确认集段遗漏阻断性要求：' . $requirement['id'], 422);
        }
        return $result;
    }
}
