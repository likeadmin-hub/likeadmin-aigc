<?php
namespace app\common\service\app\aigc_short_drama\vnext;

use RuntimeException;

/** Structural links and semantic acceptance are separate evidence, pinned to one revision. */
final class ContentCoverage
{
    public const VERSION = 1;

    /** Requirements/events are server-owned confirmed scope, never inferred from review omissions. */
    public static function evaluate(array $document, array $plan, array $requirements, array $events, array $review): array
    {
        ShotCompiler::assertCurrent($document, $plan);
        if (($review['document_hash'] ?? '') !== $document['content_hash'] || ($review['shot_hash'] ?? '') !== $plan['content_hash']) {
            throw new RuntimeException('验收报告引用了过期内容', 409);
        }
        $units = ContentDocument::units($document); $byRef = array_column($units, 'unit_id', 'ref');
        $checks = [];
        foreach (ContentDocument::rows($review['checks'] ?? [], 'review.checks') as $check) {
            $id = $check['id'] ?? '';
            if (!is_string($id) || $id === '' || isset($checks[$id])) throw new RuntimeException('验收检查标识缺失或重复', 422);
            $checks[$id] = $check;
        }
        $targets = [];
        foreach ($requirements as $item) $targets[] = $item + ['trace_stage' => 'requirement'];
        foreach ($events as $item) $targets[] = $item + ['trace_stage' => 'episode_event'];
        foreach (['identity', 'continuity', 'shot_fidelity'] as $aspect) $targets[] = ['id' => 'aspect:' . $aspect, 'blocking' => true, 'trace_stage' => 'acceptance'];
        $rows = []; $issues = []; $seen = [];
        foreach ($targets as $target) {
            $id = ContentDocument::text($target['id'] ?? null, 'requirement/event.id');
            if (isset($seen[$id])) throw new RuntimeException('确认范围存在重复标识', 422);
            $seen[$id] = true;
            $applicable = empty($target['episode_ids']) || in_array($document['episode_id'], $target['episode_ids'], true);
            $row = ['id' => $id, 'trace_stage' => $target['trace_stage'], 'revision_id' => $document['revision_id'],
                'label' => (string)($target['label'] ?? $target['text'] ?? ['aspect:identity' => '人物与发言者', 'aspect:continuity' => '前后连续性', 'aspect:shot_fidelity' => '分镜与正文一致性'][$id] ?? ''),
                'requirement_ids' => $target['trace_stage'] === 'requirement' ? [$id] : ($target['requirement_ids'] ?? []),
                'document_hash' => $document['content_hash'], 'shot_hash' => $plan['content_hash'],
                'blocking' => (bool)($target['blocking'] ?? true), 'unit_refs' => [], 'shot_ids' => [],
                'semantic_status' => 'pending', 'status' => 'pending'];
            $row += array_intersect_key($target, array_flip(['source_id', 'source_version', 'source_hash', 'start', 'end', 'source_refs', 'outline_revision', 'outline_source', 'origin']));
            if (!$applicable) { $row['status'] = 'not_applicable'; $rows[] = $row; continue; }
            $check = $checks[$id] ?? null;
            if (!$check) throw new RuntimeException('验收遗漏本集检查项：' . $id, 422);
            if (!in_array($check['status'] ?? '', ['covered', 'missing', 'contradiction', 'uncertain'], true)) throw new RuntimeException('验收结论无效：' . $id, 422);
            $evidence = $check['evidence'] ?? [];
            if (!is_array($evidence)) throw new RuntimeException('验收证据格式无效', 422);
            foreach (ContentDocument::rows($evidence, 'check.evidence') as $link) {
                $unitId = $byRef[$link['unit'] ?? ''] ?? '';
                if (!isset($units[$unitId])) throw new RuntimeException('验收引用不存在的正文', 422);
                ContentDocument::slice($units[$unitId]['text'], $link['start'] ?? null, $link['end'] ?? null, $link['quote'] ?? null);
                if (!isset($link['quote'])) throw new RuntimeException('验收证据缺少原文引文', 422);
                $row['unit_refs'][] = ['unit_id' => $unitId, 'start' => $link['start'], 'end' => $link['end'], 'text_hash' => $units[$unitId]['text_hash']];
            }
            if ($check['status'] === 'covered' && !$evidence) throw new RuntimeException('已覆盖结论缺少正文证据：' . $id, 422);
            if ($check['status'] !== 'covered') {
                ContentDocument::text($check['reason'] ?? null, 'check.reason');
                if (($check['scope'] ?? '') !== 'whole_episode') throw new RuntimeException('缺失或矛盾结论必须核对整集范围', 422);
                $issues[] = ['issue_id' => 'issue_' . substr(ContentDocument::hash([$document['revision_id'], $id, $check]), 0, 24),
                    'code' => 'content_' . $check['status'], 'severity' => $row['blocking'] ? 'blocking' : 'suggestion',
                    'target_id' => $id, 'trace_stage' => $row['trace_stage'], 'revision_id' => $document['revision_id'],
                    'evidence' => $evidence, 'reason' => $check['reason'], 'repair_unit_ids' => array_column($row['unit_refs'], 'unit_id')];
            }
            $unitIds = array_column($row['unit_refs'], 'unit_id');
            foreach ($plan['shots'] as $shot) if (array_intersect($unitIds, array_column($shot['unit_refs'], 'unit_id'))) $row['shot_ids'][] = $shot['shot_id'];
            $row['semantic_status'] = $row['status'] = $check['status']; $rows[] = $row;
        }
        if (array_diff(array_keys($checks), array_keys($seen))) throw new RuntimeException('验收包含未知检查项', 422);
        return ['check_version' => self::VERSION, 'revision_id' => $document['revision_id'],
            'document_hash' => $document['content_hash'], 'shot_hash' => $plan['content_hash'],
            'status' => array_filter($issues, static fn($issue) => $issue['severity'] === 'blocking') ? 'draft' : 'ready',
            'coverage' => $rows, 'issues' => $issues];
    }

    /** For local edits, return impacted identities rather than invalidating an entire series. */
    public static function impacted(array $before, array $after, array $plan, array $report): array
    {
        $old = ContentDocument::units($before); $new = ContentDocument::units($after); $changed = [];
        foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $id) {
            if (!isset($old[$id], $new[$id]) || ContentDocument::hash($old[$id]) !== ContentDocument::hash($new[$id])) $changed[] = $id;
        }
        $shots = []; $targets = []; $audio = [];
        foreach ($plan['shots'] as $shot) if (array_intersect($changed, array_column($shot['unit_refs'], 'unit_id'))) $shots[] = $shot['shot_id'];
        foreach ($report['coverage'] as $row) if (array_intersect($changed, array_column($row['unit_refs'], 'unit_id'))) $targets[] = $row['id'];
        foreach ($plan['audio_segments'] as $segment) if (in_array($segment['unit_id'], $changed, true)) $audio[] = $segment['audio_segment_id'];
        return ['unit_ids' => $changed, 'shot_ids' => $shots, 'check_ids' => $targets, 'audio_segment_ids' => $audio];
    }
}
