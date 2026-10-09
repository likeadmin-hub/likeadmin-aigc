<?php
namespace app\common\service\app\aigc_short_drama\vnext;

use RuntimeException;

/** Server-authenticated edit provenance. User text is exact content, never a model repair instruction. */
final class ManualContentRevision
{
    public const VERSION = 1;

    public static function capture(array $input, array $document, array $patches, string $revision, array $origin): array
    {
        self::assertOrigin($origin);
        $history = $input['context']['manual_edit_history'] ?? null;
        if ($history !== null) self::assertHistory($history);
        // Validate every submitted patch before filtering no-ops, including duplicates and stale hashes.
        ContentDocument::patch($document, $patches, $revision);
        $before = ContentDocument::units($document); $effective = [];
        foreach ($patches as $patch) if ($patch['text'] !== $before[$patch['unit_id']]['text']) $effective[] = $patch;
        if (!$effective) throw new RuntimeException('正文没有变化', 422);
        $edited = ContentDocument::patch($document, $effective, $revision);
        $eventId = 'manual_edit_' . substr(ContentDocument::hash([$origin, $document['content_hash'], $revision, $effective]), 0, 24);
        $sources = $input['context']['sources'] ?? []; $links = []; $changes = [];
        foreach ($effective as $patch) {
            $id = $patch['unit_id']; $old = $before[$id];
            $sourceId = 'manual_source_' . substr(ContentDocument::hash([$eventId, $id]), 0, 24);
            $source = SourceTrace::capture($sourceId, $revision, $patch['text'], 'faithful');
            $source['origin'] = ['kind' => 'manual_text_edit', 'event_id' => $eventId, 'unit_id' => $id,
                'base_revision_id' => $document['revision_id'], 'base_document_hash' => $document['content_hash']] + $origin;
            if (isset($sources[$sourceId]) && $sources[$sourceId] !== $source) throw new RuntimeException('手工来源标识冲突', 409);
            $sources[$sourceId] = $source;
            $links[$id] = [['source_id' => $sourceId, 'source_version' => $revision, 'source_hash' => $source['hash'],
                'start' => 0, 'end' => mb_strlen($patch['text'], 'UTF-8'), 'quote' => $patch['text']]];
            $changes[] = ['unit_id' => $id, 'scene_id' => $old['scene_id'], 'speaker_id' => $old['speaker_id'] ?? null,
                'before_text' => $old['text'], 'before_text_hash' => $old['text_hash'], 'before_source_links' => $old['source_links'],
                'after_text_hash' => $source['hash'], 'source_id' => $sourceId];
        }
        $edited = ContentDocument::attachExactSources($edited, $links, $sources);
        $events = $history['events'] ?? [];
        $event = ['schema_version' => self::VERSION, 'event_id' => $eventId, 'kind' => 'manual_text_edit',
            'base_revision_id' => $document['revision_id'], 'base_document_hash' => $document['content_hash'],
            'revision_id' => $revision, 'document_hash' => $edited['content_hash'], 'changes' => $changes,
            'previous_event_hash' => $events ? end($events)['event_hash'] : ''] + $origin;
        $event['event_hash'] = ContentDocument::hash($event); $events[] = $event;
        $history = ['schema_version' => self::VERSION, 'events' => $events]; $history['history_hash'] = ContentDocument::hash($history);
        // Keep raw historical text/actor data in the frozen job input, not in model-facing document copies.
        $edited['transforms'][] = ['code' => 'manual_text_edit', 'event_id' => $eventId, 'event_hash' => $event['event_hash'],
            'base_revision_id' => $document['revision_id'], 'unit_ids' => array_column($changes, 'unit_id')];
        return ['document' => $edited, 'sources' => $sources, 'history' => $history];
    }

    public static function assertHistory(array $history): void
    {
        $unsigned = $history; unset($unsigned['history_hash']);
        if (($history['schema_version'] ?? 0) !== self::VERSION || ($history['history_hash'] ?? '') !== ContentDocument::hash($unsigned)) {
            throw new RuntimeException('手工修改历史版本校验失败', 409);
        }
        $previous = ''; $ids = [];
        foreach (ContentDocument::rows($history['events'] ?? null, 'manual_edit_history.events') as $event) {
            $unsigned = $event; unset($unsigned['event_hash']); $id = $event['event_id'] ?? '';
            if (!is_string($id) || $id === '' || isset($ids[$id]) || ($event['schema_version'] ?? 0) !== self::VERSION
                || ($event['previous_event_hash'] ?? null) !== $previous || ($event['event_hash'] ?? '') !== ContentDocument::hash($unsigned)) {
                throw new RuntimeException('手工修改来源链不完整', 409);
            }
            $ids[$id] = true; $previous = $event['event_hash'];
        }
    }

    public static function assertOrigin(array $origin): void
    {
        foreach (['tenant_id', 'user_id', 'created_at'] as $field) if (!is_int($origin[$field] ?? null) || $origin[$field] <= 0) throw new RuntimeException('手工修改来源身份无效', 409);
        foreach (['parent_task_id', 'task_id'] as $field) ContentDocument::text($origin[$field] ?? null, 'edit.' . $field);
        if ($origin['parent_task_id'] === $origin['task_id']) throw new RuntimeException('手工修改须建立新任务', 409);
    }
}
