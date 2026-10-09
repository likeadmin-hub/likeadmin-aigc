<?php
namespace app\common\service\app\aigc_short_drama\vnext;

use RuntimeException;

/** Visual references may overlap; audio is compiled once from the canonical dialogue order. */
final class ShotCompiler
{
    public const VERSION = 1;

    public static function compile(array $document, array $reply, string $revision): array
    {
        ContentDocument::text($revision, 'shot_revision_id');
        if (empty($reply['shots']) || !is_array($reply['shots'])) throw new RuntimeException('分镜规划为空', 422);
        $units = ContentDocument::units($document); $refs = array_column($units, 'unit_id', 'ref');
        $names = array_column($document['entities'], 'canonical_name', 'entity_id');
        $coverage = []; $shots = []; $firstDialogue = []; $dialogueOrder = [];
        foreach ($units as $id => $unit) if ($unit['type'] === 'dialogue') $dialogueOrder[] = $id;
        foreach (ContentDocument::rows($reply['shots'], 'shots') as $i => $shot) {
            if (empty($shot['refs']) || !is_array($shot['refs'])) throw new RuntimeException('分镜缺少正文引用', 422);
            $links = []; $entities = []; $locations = []; $lines = [];
            foreach (ContentDocument::rows($shot['refs'], 'shot.refs') as $ref) {
                $id = $refs[$ref['unit'] ?? ''] ?? '';
                if (!isset($units[$id])) throw new RuntimeException('分镜引用不存在的正文', 422);
                $unit = $units[$id]; $start = $ref['start'] ?? 0; $end = $ref['end'] ?? mb_strlen($unit['text'], 'UTF-8');
                $text = ContentDocument::slice($unit['text'], $start, $end);
                $links[] = ['unit_id' => $id, 'start' => $start, 'end' => $end, 'text_hash' => $unit['text_hash']];
                $coverage[$id][] = [$start, $end]; $entities = array_merge($entities, $unit['entity_ids']);
                $locations[$unit['location_id']] = true;
                if ($unit['type'] === 'dialogue') {
                    if (!isset($firstDialogue[$id])) $firstDialogue[$id] = count($firstDialogue);
                    $lines[] = ($unit['speaker_id'] === null ? '旁白' : $names[$unit['speaker_id']]) . '：' . $text;
                }
            }
            if (count($locations) !== 1) throw new RuntimeException('同一叙事分镜引用跨场景内容，请拆开转场', 422);
            $visual = ContentDocument::text($shot['visual_description'] ?? null, 'shot.visual_description');
            $id = 'shot_' . substr(ContentDocument::hash([$revision, $i + 1]), 0, 24);
            $shots[] = ['shot_id' => $id, 'order' => $i + 1, 'revision_id' => $revision,
                'document_revision_id' => $document['revision_id'], 'document_hash' => $document['content_hash'],
                'unit_refs' => $links, 'location_id' => key($locations), 'entity_ids' => array_values(array_unique($entities)),
                'visual_description' => $visual, 'composition' => (string)($shot['composition'] ?? ''),
                'camera_movement' => (string)($shot['camera_movement'] ?? ''), 'dialogue_preview' => implode("\n", $lines)];
        }
        if (array_keys($firstDialogue) !== $dialogueOrder) throw new RuntimeException('分镜对白缺失或首次出现顺序与正文不符', 422);
        foreach ($units as $id => $unit) {
            $ranges = $coverage[$id] ?? []; sort($ranges); $end = 0;
            foreach ($ranges as $range) {
                if ($range[0] > $end) throw new RuntimeException('分镜遗漏正文片段：' . $unit['ref'], 422);
                $end = max($end, $range[1]);
            }
            if ($end !== mb_strlen($unit['text'], 'UTF-8')) throw new RuntimeException('分镜未完整覆盖正文：' . $unit['ref'], 422);
        }
        $audio = [];
        foreach ($units as $id => $unit) if ($unit['type'] === 'dialogue') {
            $audio[] = ['audio_segment_id' => 'audio_' . substr(ContentDocument::hash([$document['revision_id'], $id]), 0, 24),
                'unit_id' => $id, 'speaker_id' => $unit['speaker_id'], 'speech_type' => $unit['speech_type'],
                'start' => 0, 'end' => mb_strlen($unit['text'], 'UTF-8'), 'text_hash' => $unit['text_hash'],
                'document_revision_id' => $document['revision_id']];
        }
        $result = ['schema_version' => self::VERSION, 'revision_id' => $revision,
            'document_revision_id' => $document['revision_id'], 'document_hash' => $document['content_hash'],
            'offset_unit' => 'unicode_code_point', 'shots' => $shots, 'audio_segments' => $audio];
        $result['content_hash'] = ContentDocument::hash($result);
        return $result;
    }

    public static function assertCurrent(array $document, array $plan): void
    {
        if (($plan['document_revision_id'] ?? '') !== $document['revision_id'] || ($plan['document_hash'] ?? '') !== $document['content_hash']) {
            throw new RuntimeException('正文已更新，请重新编译受影响的分镜和声音段', 409);
        }
    }
}
