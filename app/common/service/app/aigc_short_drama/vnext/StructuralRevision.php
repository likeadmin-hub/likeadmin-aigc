<?php
namespace app\common\service\app\aigc_short_drama\vnext;

use RuntimeException;

/** Explicit identity/location edits. Every word and logical unit is preserved until reviewed. */
final class StructuralRevision
{
    public static function targets(array $document): array
    {
        $result = ['entities' => [], 'scenes' => [], 'bindings' => []];
        foreach ($document['entities'] as $entity) $result['entities'][] = ['entity_id' => $entity['entity_id'], 'before_hash' => ContentDocument::hash($entity)];
        foreach ($document['scenes'] as $scene) {
            $result['scenes'][] = ['scene_id' => $scene['scene_id'], 'before_hash' => ContentDocument::hash(self::sceneState($scene))];
            foreach ($scene['units'] as $unit) $result['bindings'][] = ['unit_id' => $unit['unit_id'], 'before_hash' => ContentDocument::hash(self::binding($unit))];
        }
        return $result;
    }

    public static function capture(array $input, array $document, array $edits, string $revision, array $origin): array
    {
        ManualContentRevision::assertOrigin($origin);
        $unsigned = $document; unset($unsigned['content_hash'], $unsigned['status'], $unsigned['transforms']);
        if (($document['content_hash'] ?? '') !== ContentDocument::hash($unsigned)) throw new RuntimeException('正文结构基线已变化', 409);
        if (array_diff(array_keys($edits), ['entities', 'scenes', 'bindings'])) throw new RuntimeException('结构修改含不允许的字段', 422);
        $count = 0;
        foreach (['entities', 'scenes', 'bindings'] as $group) $count += count(ContentDocument::rows($edits[$group] ?? [], 'structure.' . $group));
        if ($count > 256) throw new RuntimeException('单次结构修改超过容量，请分批保存', 413);
        $history = $input['context']['manual_edit_history'] ?? null;
        if ($history !== null) ManualContentRevision::assertHistory($history);
        $entities = array_column($document['entities'], null, 'entity_id'); $changes = []; $seen = [];
        $record = static function (string $kind, string $id, array $before, array $after, string $label) use (&$changes): void {
            if ($before === $after) return;
            $changes[] = ['unit_id' => $kind . ':' . $id, 'target_kind' => $kind, 'target_id' => $id, 'target_label' => $label,
                'before_text' => json_encode($before, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'after_text' => json_encode($after, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'before_source_links' => []];
        };
        $assertPatch = static function (array $patch, string $id, array $before, array $allowed) use (&$seen): void {
            if (isset($seen[$id]) || ($patch['before_hash'] ?? '') !== ContentDocument::hash($before)) throw new RuntimeException('结构修改基线已变化或目标重复', 409);
            if (array_diff(array_keys($patch), $allowed)) throw new RuntimeException('结构修改含不允许的字段', 422);
            $seen[$id] = true;
        };
        foreach (ContentDocument::rows($edits['entities'] ?? [], 'structure.entities') as $patch) {
            $id = $patch['entity_id'] ?? '';
            if (!is_string($id)) throw new RuntimeException('修改对象标识无效', 422);
            $old = $entities[$id] ?? null;
            if (!$old) throw new RuntimeException('修改对象不属于当前剧本', 409);
            $assertPatch($patch, 'entity:' . $id, $old, ['entity_id', 'before_hash', 'canonical_name', 'description']);
            $after = $old;
            if (array_key_exists('canonical_name', $patch)) {
                $name = trim(ContentDocument::text($patch['canonical_name'], 'entity.canonical_name'));
                if (mb_strlen($name, 'UTF-8') > 80) throw new RuntimeException('名称超过 80 字，请缩短名称', 422);
                foreach ($entities as $otherId => $other) if ($otherId !== $id && $other['kind'] === $old['kind'] && $name === $other['canonical_name'] && $name !== $old['canonical_name']) throw new RuntimeException('名称与另一主体或地点重名，请明确区分', 422);
                $after['canonical_name'] = $name;
                $after['aliases'] = array_values(array_unique(array_merge($old['aliases'], [$old['canonical_name'], $name])));
            }
            if (array_key_exists('description', $patch)) {
                if (!is_string($patch['description']) || !mb_check_encoding($patch['description'], 'UTF-8')) throw new RuntimeException('设定说明应为完整文本', 422);
                $after['description'] = $patch['description'];
            }
            $entities[$id] = $after; $record('entity', $id, $old, $after, ($old['kind'] === 'location' ? '地点：' : '主体：') . $old['canonical_name']);
        }
        $scenePatches = []; $bindingPatches = [];
        foreach (ContentDocument::rows($edits['scenes'] ?? [], 'structure.scenes') as $patch) $scenePatches[] = $patch;
        foreach (ContentDocument::rows($edits['bindings'] ?? [], 'structure.bindings') as $patch) $bindingPatches[] = $patch;
        $foundScenes = 0; $foundBindings = 0;
        foreach ($document['scenes'] as &$scene) {
            foreach ($scenePatches as $patch) if (($patch['scene_id'] ?? '') === $scene['scene_id']) {
                $old = self::sceneState($scene); $assertPatch($patch, 'scene:' . $scene['scene_id'], $old, ['scene_id', 'before_hash', 'location_id', 'time_context']);
                if (array_key_exists('location_id', $patch)) {
                    if (!is_string($patch['location_id']) || ($entities[$patch['location_id']]['kind'] ?? '') !== 'location') throw new RuntimeException('场次地点须选择当前已登记地点', 422);
                    $scene['location_id'] = $patch['location_id'];
                }
                if (array_key_exists('time_context', $patch)) {
                    if (!is_string($patch['time_context']) || !mb_check_encoding($patch['time_context'], 'UTF-8')) throw new RuntimeException('场次时间应为文本', 422);
                    $scene['time_context'] = $patch['time_context'];
                }
                $record('scene', $scene['scene_id'], $old, self::sceneState($scene), '场次地点与时间'); $foundScenes++;
            }
            foreach ($scene['units'] as &$unit) foreach ($bindingPatches as $patch) if (($patch['unit_id'] ?? '') === $unit['unit_id']) {
                $old = self::binding($unit); $assertPatch($patch, 'binding:' . $unit['unit_id'], $old, ['unit_id', 'before_hash', 'entity_ids', 'speaker_id', 'speech_type']);
                if (array_key_exists('entity_ids', $patch)) {
                    if (!is_array($patch['entity_ids']) || array_values($patch['entity_ids']) !== $patch['entity_ids']) throw new RuntimeException('参与主体须为列表', 422);
                    foreach ($patch['entity_ids'] as $id) if (!is_string($id) || !isset($entities[$id]) || !in_array($entities[$id]['kind'], ['character', 'prop'], true)) throw new RuntimeException('参与主体未登记', 422);
                    $unit['entity_ids'] = array_values(array_unique($patch['entity_ids']));
                }
                if ($unit['type'] === 'dialogue') {
                    $speech = $patch['speech_type'] ?? $unit['speech_type']; $speaker = array_key_exists('speaker_id', $patch) ? $patch['speaker_id'] : $unit['speaker_id'];
                    if (!in_array($speech, ['spoken', 'voiceover', 'narration'], true)) throw new RuntimeException('发声类型无效', 422);
                    if (($speech === 'narration' && $speaker !== null) || ($speech !== 'narration' && (!is_string($speaker) || ($entities[$speaker]['kind'] ?? '') !== 'character'))) throw new RuntimeException('对白须明确选择人物，旁白不绑定发言者', 422);
                    $unit['speech_type'] = $speech; $unit['speaker_id'] = $speaker;
                    if ($speaker && !in_array($speaker, $unit['entity_ids'], true)) $unit['entity_ids'][] = $speaker;
                } elseif (array_key_exists('speaker_id', $patch) || array_key_exists('speech_type', $patch)) throw new RuntimeException('动作单元不能设置发言者', 422);
                $record('binding', $unit['unit_id'], $old, self::binding($unit), '人物与发声绑定'); $foundBindings++;
            }
            unset($unit);
        }
        unset($scene);
        if ($foundScenes !== count($scenePatches) || $foundBindings !== count($bindingPatches)) throw new RuntimeException('修改场次或单元不属于当前剧本', 409);
        if (!$changes) throw new RuntimeException('结构没有变化', 422);
        $document['entities'] = array_values($entities);
        $base = $unsigned; $base['content_hash'] = ContentDocument::hash($unsigned);
        $edited = ContentDocument::rebind($document, $document['dependency_versions'], $revision);
        $eventId = 'manual_structure_' . substr(ContentDocument::hash([$origin, $base['content_hash'], $revision, $changes]), 0, 24);
        $sources = $input['context']['sources'] ?? [];
        foreach ($changes as &$change) {
            $sourceId = 'manual_source_' . substr(ContentDocument::hash([$eventId, $change['unit_id']]), 0, 24);
            $source = SourceTrace::capture($sourceId, $revision, $change['after_text'], 'faithful');
            $source['origin'] = ['kind' => 'manual_structure_edit', 'event_id' => $eventId, 'unit_id' => $change['unit_id']] + $origin;
            $sources[$sourceId] = $source;
            $change['source_id'] = $sourceId; $change['before_text_hash'] = hash('sha256', $change['before_text']); $change['after_text_hash'] = $source['hash'];
            unset($change['after_text']);
        }
        unset($change);
        $events = $history['events'] ?? [];
        $event = ['schema_version' => ManualContentRevision::VERSION, 'kind' => 'manual_structure_edit', 'event_id' => $eventId,
            'base_revision_id' => $base['revision_id'], 'base_document_hash' => $base['content_hash'], 'revision_id' => $revision,
            'document_hash' => $edited['content_hash'], 'changes' => $changes, 'previous_event_hash' => $events ? end($events)['event_hash'] : ''] + $origin;
        $event['event_hash'] = ContentDocument::hash($event); $events[] = $event;
        $history = ['schema_version' => ManualContentRevision::VERSION, 'events' => $events]; $history['history_hash'] = ContentDocument::hash($history);
        $edited['transforms'][] = ['code' => 'manual_structure_edit', 'event_id' => $eventId, 'event_hash' => $event['event_hash']];
        return ['document' => $edited, 'sources' => $sources, 'history' => $history];
    }

    private static function sceneState(array $scene): array { return array_intersect_key($scene, array_flip(['scene_id', 'location_id', 'time_context'])); }
    private static function binding(array $unit): array { return array_intersect_key($unit, array_flip(['unit_id', 'type', 'entity_ids', 'speaker_id', 'speech_type', 'text_hash'])); }
}
