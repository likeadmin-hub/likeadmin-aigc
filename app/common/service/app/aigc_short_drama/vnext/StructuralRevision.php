<?php
namespace app\common\service\app\aigc_short_drama\vnext;

use RuntimeException;

/** Explicit identity/location edits. Every word and logical unit is preserved until reviewed. */
final class StructuralRevision
{
    public static function targets(array $document, array $ledgers = []): array
    {
        $result = ['entities' => [], 'scenes' => [], 'bindings' => []];
        foreach ($document['entities'] as $entity) $result['entities'][] = ['entity_id' => $entity['entity_id'], 'before_hash' => ContentDocument::hash($entity)];
        foreach ($document['scenes'] as $scene) {
            $result['scenes'][] = ['scene_id' => $scene['scene_id'], 'before_hash' => ContentDocument::hash(self::sceneState($scene))];
            foreach ($scene['units'] as $unit) $result['bindings'][] = ['unit_id' => $unit['unit_id'], 'before_hash' => ContentDocument::hash(self::binding($unit))];
        }
        $result['scene_layout'] = ['before_hash' => ContentDocument::hash(self::layout($document))];
        $result['entity_registry'] = ['before_hash' => ContentDocument::hash($document['entities']), 'protected_entity_ids' => EntityRegistryRevision::protectedIds($ledgers)];
        return $result;
    }

    public static function capture(array $input, array $document, array $edits, string $revision, array $origin, array $ledger = []): array
    {
        ManualContentRevision::assertOrigin($origin);
        $unsigned = $document; unset($unsigned['content_hash'], $unsigned['status'], $unsigned['transforms']);
        if (($document['content_hash'] ?? '') !== ContentDocument::hash($unsigned)) throw new RuntimeException('正文结构基线已变化', 409);
        if (array_diff(array_keys($edits), ['entities', 'scenes', 'bindings', 'scene_layout', 'entity_registry'])) throw new RuntimeException('结构修改含不允许的字段', 422);
        $layoutBefore = self::layout($document);
        if (isset($edits['scene_layout']) && !empty($edits['scenes'])) throw new RuntimeException('拆分或合并时请在新场次布局中设置地点和时间', 422);
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
        $registry = ['refs' => [], 'removals' => []];
        if (array_key_exists('entity_registry', $edits)) {
            if (!is_array($edits['entity_registry'])) throw new RuntimeException('主体登记修改无效', 422);
            $registry = EntityRegistryRevision::prepare($document, $edits['entity_registry'], $revision); $entities = $registry['entities'];
            foreach ($registry['changes'] as $change) $record('entity_add', $change['id'], $change['before'], $change['after'], $change['label']);
        }
        // Resolve request-local choices once. Persist only server-assigned identities in scenes and units.
        $resolve = static fn($id) => is_string($id) ? ($registry['refs'][$id] ?? $id) : $id;
        foreach (ContentDocument::rows($edits['entities'] ?? [], 'structure.entities') as $patch) {
            $id = $patch['entity_id'] ?? '';
            if (!is_string($id)) throw new RuntimeException('修改对象标识无效', 422);
            $old = $entities[$id] ?? null;
            if (!$old) throw new RuntimeException('修改对象不属于当前剧本', 409);
            if (isset($registry['removals'][$id]) || in_array($id, $registry['refs'], true)) throw new RuntimeException('不能同时修改和移除或重新修改新增主体', 422);
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
        foreach (ContentDocument::rows($edits['scenes'] ?? [], 'structure.scenes') as $patch) {
            if (array_key_exists('location_id', $patch)) $patch['location_id'] = $resolve($patch['location_id']);
            $scenePatches[] = $patch;
        }
        foreach (ContentDocument::rows($edits['bindings'] ?? [], 'structure.bindings') as $patch) {
            if (array_key_exists('speaker_id', $patch)) $patch['speaker_id'] = $resolve($patch['speaker_id']);
            if (is_array($patch['entity_ids'] ?? null)) $patch['entity_ids'] = array_map($resolve, $patch['entity_ids']);
            $bindingPatches[] = $patch;
        }
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
        if (array_key_exists('scene_layout', $edits)) {
            $layout = $edits['scene_layout'];
            if (!is_array($layout) || array_diff(array_keys($layout), ['before_hash', 'scenes']) || ($layout['before_hash'] ?? '') !== ContentDocument::hash($layoutBefore)) throw new RuntimeException('场次布局基线已变化', 409);
            $groups = ContentDocument::rows($layout['scenes'] ?? null, 'scene_layout.scenes');
            if (!$groups || count($groups) > 256) throw new RuntimeException('场次布局不能为空或超过 256 场', 422);
            $units = ContentDocument::units($document); $order = []; $scenes = [];
            foreach ($groups as $index => $group) {
                if (array_key_exists('location_id', $group)) $group['location_id'] = $resolve($group['location_id']);
                if (array_diff(array_keys($group), ['location_id', 'time_context', 'unit_ids']) || !is_string($group['location_id'] ?? null)
                    || ($entities[$group['location_id']]['kind'] ?? '') !== 'location' || !is_string($group['time_context'] ?? null)
                    || !mb_check_encoding($group['time_context'], 'UTF-8') || !is_array($group['unit_ids'] ?? null)
                    || !$group['unit_ids'] || array_values($group['unit_ids']) !== $group['unit_ids']) throw new RuntimeException('新场次须选择已登记地点并保留正文单元', 422);
                $scene = ['scene_id' => 'scene_' . substr(ContentDocument::hash([$revision, 'layout', $index + 1]), 0, 24),
                    'location_id' => $group['location_id'], 'time_context' => $group['time_context'], 'units' => []];
                foreach ($group['unit_ids'] as $id) {
                    if (!is_string($id) || !isset($units[$id])) throw new RuntimeException('场次布局含不属于当前正文的单元', 409);
                    $unit = $units[$id]; unset($unit['scene_id'], $unit['location_id']); $scene['units'][] = $unit; $order[] = $id;
                }
                foreach ($layoutBefore as $oldIndex => $old) if (ContentDocument::hash($old) === ContentDocument::hash($group)) $scene['scene_id'] = $document['scenes'][$oldIndex]['scene_id'];
                $scenes[] = $scene;
                $groups[$index] = $group;
            }
            if ($order !== array_keys($units)) throw new RuntimeException('拆分或合并必须逐字保留全部单元和顺序，不能遗漏、复制或重排正文', 409);
            if (ContentDocument::hash($groups) !== ContentDocument::hash($layoutBefore)) {
                $document['scenes'] = $scenes;
                $record('layout', 'scenes', $layoutBefore, $groups, '场次拆分与合并');
            }
        }
        if ($registry['removals']) {
            $entities = EntityRegistryRevision::remove($document, $entities, $registry['removals'], [$ledger, $input['context']['previous_narrative'] ?? []]);
            foreach ($registry['removals'] as $id => $old) $record('entity_remove', $id, $old, [], '从本集移除' . EntityRegistryRevision::kindName($old['kind']) . '：' . $old['canonical_name']);
        }
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
    private static function layout(array $document): array
    {
        return array_map(static fn(array $scene): array => ['location_id' => $scene['location_id'], 'time_context' => $scene['time_context'], 'unit_ids' => array_column($scene['units'], 'unit_id')], $document['scenes']);
    }
}
