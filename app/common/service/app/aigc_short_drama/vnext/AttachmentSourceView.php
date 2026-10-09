<?php
namespace app\common\service\app\aigc_short_drama\vnext;

use RuntimeException;

/** One complete unit per read, from the owned frozen input only. No file or external task lookup. */
final class AttachmentSourceView
{
    public static function page(array $input, array $candidate, int $page): array
    {
        if ($page < 1) throw new RuntimeException('附件来源页码无效', 422);
        $context = $input['context'] ?? [];
        $empty = ['status' => 'not_recorded', 'page' => $page, 'total' => 0, 'records' => [], 'next_page' => null];
        if (!isset($context['attachment_import'])) return $empty;
        $import = $context['attachment_import'];
        // A manual edit removes structured_screenplay, but the original import stays frozen.
        AttachmentScreenplay::document(array_replace($context, ['structured_screenplay' => $import['screenplay']]));
        $document = $candidate['document'] ?? $context['canonical_input'] ?? null;
        if ($document === null) return array_replace($empty, ['status' => 'not_ready']);
        $unsigned = $document; unset($unsigned['content_hash'], $unsigned['status'], $unsigned['transforms']);
        if (($document['content_hash'] ?? '') !== ContentDocument::hash($unsigned)
            || ($document['revision_id'] ?? '') !== ($context['revision_id'] ?? null)) throw new RuntimeException('附件来源与正文版本不一致', 409);
        $traces = array_values(array_filter($document['transforms'] ?? [], static fn($row) => ($row['code'] ?? '') === 'attachment_import'));
        if (count($traces) !== 1) throw new RuntimeException('附件正文布局记录缺失或重复', 409);
        $trace = $traces[0]; $source = $import['source']; $origin = $source['origin'];
        $layout = $trace['layout'] ?? [];
        $baseLayout = array_map(static function ($row) { unset($row['unit_id'], $row['text_hash']); return $row; }, $layout);
        if (($trace['schema_version'] ?? 0) !== $import['schema_version'] || $baseLayout !== $import['layout']
            || ($trace['source_id'] ?? '') !== $source['source_id'] || ($trace['source_hash'] ?? '') !== $source['hash']
            || ($trace['origin'] ?? []) !== $origin || ($origin['kind'] ?? '') !== 'attachment_episode'
            || !is_int($origin['start'] ?? null) || !is_int($origin['end'] ?? null) || $origin['start'] < 0
            || $origin['end'] - $origin['start'] !== mb_strlen($source['text'], 'UTF-8')) throw new RuntimeException('附件原文范围或布局不一致', 409);
        $units = ContentDocument::units($document); $entries = array_values(array_filter($layout, static fn($row) => $row['kind'] === 'content'));
        $ids = array_column($entries, 'unit_id');
        if ($ids !== array_keys($units)) throw new RuntimeException('附件正文单元已变化，不能沿用原位置', 409);
        $total = count($entries);
        $result = ['status' => 'recorded', 'page' => $page, 'total' => $total, 'records' => [], 'next_page' => $page < $total ? $page + 1 : null,
            'revision_id' => $document['revision_id'], 'document_hash' => $document['content_hash']];
        if ($page > $total) return $result;
        $entry = $entries[$page - 1]; $unit = $units[$entry['unit_id']];
        $original = $import['screenplay']['scenes'][$entry['scene_index']]['units'][$entry['unit_index']];
        $end = $entry['text_start'] + mb_strlen($original['text'], 'UTF-8');
        $quote = ContentDocument::slice($source['text'], $entry['text_start'], $end, $original['text']);
        if (($entry['text_hash'] ?? '') !== hash('sha256', $quote) || ($unit['text_hash'] ?? '') !== hash('sha256', $unit['text'])) throw new RuntimeException('附件正文文本校验失败', 409);
        $unchanged = $unit['text'] === $quote;
        if ($unchanged) {
            $expected = ['source_id' => $source['source_id'], 'source_version' => $source['version'], 'source_hash' => $source['hash'],
                'start' => $entry['text_start'], 'end' => $end, 'quote' => $quote];
            // Restoring the same words by hand is still a manual source, not an attachment link.
            $unchanged = in_array($expected, $unit['source_links'], true);
        }
        $names = array_column($document['entities'], 'canonical_name', 'entity_id');
        $result['records'][] = ['unit_id' => $unit['unit_id'], 'unit_number' => $page, 'type' => $unit['type'],
            'speaker_name' => $unit['type'] === 'dialogue' ? ($unit['speaker_id'] === null ? '旁白' : $names[$unit['speaker_id']]) : '',
            'scene_name' => $names[$unit['location_id']], 'relation' => $unchanged ? 'verbatim' : 'edited',
            'episode_line' => substr_count(mb_substr($source['text'], 0, $entry['start'], 'UTF-8'), "\n") + 1,
            'source_start' => $origin['start'] + $entry['text_start'], 'source_end' => $origin['start'] + $end,
            'original_line' => ContentDocument::slice($source['text'], $entry['start'], $entry['end']),
            'original_text' => $quote, 'current_text' => $unit['text']];
        return $result;
    }
}
