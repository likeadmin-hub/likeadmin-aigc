<?php
namespace app\common\service\app\aigc_short_drama\vnext;

/** Model-facing copies only. Canonical content, provenance and dependency hashes stay on the server. */
final class PromptProjection
{
    public const VERSION = 1;

    public static function apply(array $data): array
    {
        // These fields govern server publication, not story facts. In a long series
        // the dependency vector can dwarf the complete screenplay itself.
        unset($data['dependency_versions'], $data['entity_scope'], $data['identity_revision_receipts'], $data['entity_merge_receipts']);
        $texts = [];
        foreach (['prompt', 'original_input'] as $field) if (isset($data[$field]) && is_string($data[$field])) $texts[$field] = $data[$field];
        foreach (['input_history', 'requirements', 'events'] as $field) foreach ($data[$field] ?? [] as $index => $row) {
            if (isset($row['text']) && is_string($row['text'])) $texts[$field . '.' . $index . '.text'] = $row['text'];
        }
        foreach ($data['current_episode'] ?? [] as $field => $text) if (is_string($text)) $texts['current_episode.' . $field] = $text;
        foreach ($data['sources'] ?? [] as $id => $source) {
            if (!isset($source['text']) || !is_string($source['text'])) continue;
            $path = array_search($source['text'], $texts, true);
            if ($path === false) continue; // Unrepresented source text must remain verbatim.
            unset($data['sources'][$id]['text']);
            $data['sources'][$id]['text_ref'] = $path;
        }
        if (isset($data['entities'], $data['confirmed_facts']['identities']) && $data['entities'] === $data['confirmed_facts']['identities']) {
            unset($data['confirmed_facts']['identities']);
            $data['confirmed_facts']['identities_ref'] = 'entities';
        }
        if (isset($data['document'])) {
            unset($data['document']['dependency_versions'], $data['document']['transforms'], $data['document']['identity_revision_receipts'], $data['document']['entity_merge_receipts']);
        }
        foreach (['world_facts', 'character_beliefs'] as $group) foreach ($data['previous_narrative'][$group] ?? [] as $index => $record) {
            // Keep every selected fact, observer, value and verbatim evidence quote.
            unset($data['previous_narrative'][$group][$index]['document_hash']);
            foreach ($record['evidence'] ?? [] as $e => $evidence) unset($data['previous_narrative'][$group][$index]['evidence'][$e]['text_hash']);
        }
        if (isset($data['document'], $data['shot_plan'])) $data['shot_plan'] = self::shots($data['document'], $data['shot_plan']);
        return $data;
    }

    private static function shots(array $document, array $plan): array
    {
        ShotCompiler::assertCurrent($document, $plan);
        $units = ContentDocument::units($document); $names = array_column($document['entities'], 'canonical_name', 'entity_id');
        foreach ($plan['shots'] as &$shot) {
            $lines = [];
            foreach ($shot['unit_refs'] as &$ref) {
                $unit = $units[$ref['unit_id']];
                if ($unit['type'] === 'dialogue') $lines[] = ($unit['speaker_id'] === null ? '旁白' : $names[$unit['speaker_id']]) . '：'
                    . ContentDocument::slice($unit['text'], $ref['start'], $ref['end']);
                unset($ref['text_hash']);
            }
            unset($ref);
            // Only replace an exact compiler-derived duplicate, never arbitrary extra text.
            if (($shot['dialogue_preview'] ?? null) === implode("\n", $lines)) {
                unset($shot['dialogue_preview']); $shot['dialogue_from_unit_refs'] = true;
            }
            unset($shot['document_hash'], $shot['document_revision_id'], $shot['revision_id']);
        }
        unset($shot);
        foreach ($plan['audio_segments'] as &$audio) unset($audio['text_hash'], $audio['document_revision_id']);
        unset($audio);
        return $plan;
    }
}
