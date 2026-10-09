<?php
namespace app\common\service\app\aigc_short_drama\vnext;

use RuntimeException;

/** Lossless model-facing evidence retrieval. Never prune facts, paraphrase quotes or change the ledger. */
final class NarrativeHistoryIndex
{
    public const VERSION = 1;
    private const GROUPS = ['world_facts', 'character_beliefs'];

    public static function pack(array $history): array
    {
        if (isset($history['evidence_index_version'])) { self::expand($history); return $history; }
        $packed = ['evidence_index_version' => self::VERSION, 'selection_hash' => ContentDocument::hash($history),
            'world_facts' => [], 'character_beliefs' => [], 'source_documents' => [], 'evidence_index' => []];
        $sources = []; $evidence = [];
        foreach (self::GROUPS as $group) foreach (ContentDocument::rows($history[$group] ?? [], $group) as $record) {
            if (isset($record['source_ref']) || isset($record['evidence_refs'])) throw new RuntimeException('历史事实包含保留索引字段', 409);
            $source = [];
            foreach (['episode_id', 'revision_id', 'document_hash'] as $field) $source[$field] = ContentDocument::text($record[$field] ?? null, 'history.' . $field);
            $hash = ContentDocument::hash($source);
            if (!isset($sources[$hash])) {
                $sources[$hash] = 'h' . (count($sources) + 1); $packed['source_documents'][$sources[$hash]] = $source;
            }
            $sourceRef = $sources[$hash]; $refs = [];
            foreach (ContentDocument::rows($record['evidence'] ?? [], 'history.evidence') as $link) {
                $entry = ['source_ref' => $sourceRef, 'link' => $link]; $hash = ContentDocument::hash($entry);
                if (!isset($evidence[$hash])) {
                    $evidence[$hash] = 'q' . (count($evidence) + 1); $packed['evidence_index'][$evidence[$hash]] = $entry;
                }
                $refs[] = $evidence[$hash];
            }
            unset($record['episode_id'], $record['revision_id'], $record['document_hash'], $record['evidence']);
            $packed[$group][] = $record + ['source_ref' => $sourceRef, 'evidence_refs' => $refs];
        }
        // Compare against the existing model projection, which already drops audit-only hashes.
        $plain = PromptProjection::apply(['previous_narrative' => $history])['previous_narrative'];
        if (self::size($packed) >= self::size($plain) || self::estimate($packed) >= self::estimate($plain)) return $history;
        if (ContentDocument::hash(self::expand($packed)) !== ContentDocument::hash($history)) throw new RuntimeException('历史索引不能完整还原事实', 409);
        return $packed;
    }

    /** Resolve references using the frozen inline index, without DB lookups or mutable external storage. */
    public static function expand(array $index): array
    {
        if (($index['evidence_index_version'] ?? 0) !== self::VERSION) throw new RuntimeException('历史证据索引版本不支持', 409);
        $result = ['world_facts' => [], 'character_beliefs' => []];
        foreach (self::GROUPS as $group) foreach (ContentDocument::rows($index[$group] ?? [], $group) as $record) {
            $sourceRef = $record['source_ref'] ?? null;
            $source = is_string($sourceRef) ? ($index['source_documents'][$sourceRef] ?? null) : null;
            $refs = $record['evidence_refs'] ?? null;
            if (!is_array($source) || array_keys($source) !== ['episode_id', 'revision_id', 'document_hash']
                || !is_array($refs) || array_values($refs) !== $refs) throw new RuntimeException('历史证据索引引用不完整', 409);
            foreach ($source as $value) if (!is_string($value) || $value === '') throw new RuntimeException('历史证据来源无效', 409);
            $links = [];
            foreach ($refs as $ref) {
                $entry = is_string($ref) ? ($index['evidence_index'][$ref] ?? null) : null;
                if (!is_array($entry) || ($entry['source_ref'] ?? '') !== $sourceRef || !is_array($entry['link'] ?? null)) throw new RuntimeException('历史证据缺失或跨版本错配', 409);
                $links[] = $entry['link'];
            }
            unset($record['source_ref'], $record['evidence_refs']);
            if (array_intersect(array_keys($record), ['episode_id', 'revision_id', 'document_hash', 'evidence'])) throw new RuntimeException('历史索引存在冲突来源', 409);
            $result[$group][] = $record + $source + ['evidence' => $links];
        }
        if (($index['selection_hash'] ?? '') !== ContentDocument::hash($result)) throw new RuntimeException('历史证据索引与选定事实不符', 409);
        return $result;
    }

    private static function size(array $value): int
    { return strlen(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)); }
    private static function estimate(array $value): int
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return (int)ceil(max(mb_strlen($json, 'UTF-8') / 1.5, strlen($json) / 3));
    }
}
