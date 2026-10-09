<?php
namespace app\common\service\app\aigc_short_drama\vnext;

use RuntimeException;

/** Locate an already confirmed omission; never turn a location proposal into coverage evidence. */
final class MissingContentLocation
{
    public const VERSION = 1;

    public static function run(array $context, array $document, array $shots, array $report, callable $call, string $key): ?array
    {
        if (isset($context['canonical_input']) || isset($context['structured_screenplay']) || ($context['source_mode'] ?? '') === 'faithful') return null;
        $baseline = SceneContentRepair::baseline($document, $shots, $report);
        if ($baseline === null) return null;
        $targets = [];
        foreach (array_merge($context['requirements'] ?? [], $context['events'] ?? []) as $target) {
            if (empty($target['episode_ids']) || in_array($document['episode_id'], $target['episode_ids'], true)) $targets[] = $target['id'];
        }
        $issues = [];
        foreach ($report['issues'] as $issue) if ($issue['severity'] === 'blocking') {
            // Mixed/uncertain/identity failures need a different decision, not a guessed insertion.
            if ($issue['code'] !== 'content_missing' || !in_array($issue['target_id'], $targets, true)) return null;
            if (empty($issue['repair_unit_ids'])) $issues[] = $issue;
        }
        if (!$issues) return null;
        $anchors = array_keys(array_filter($baseline['owners'], static fn($owners) => count($owners) === 1));
        if (!$anchors) return null;
        $data = GenerationPipeline::reviewData($context, $document, $shots); unset($data['shot_plan']);
        $data['shot_hash'] = $shots['content_hash']; $data['localization_issues'] = $issues; $data['allowed_anchor_unit_ids'] = $anchors;
        $locations = $call($key, $data,
            '整集核验已经确认localization_issues内容缺失。本阶段仅定位补入位置，不能创作正文、修改核验结论或把定位当成覆盖证据。'
            . '阅读全文及完整本集要求、事件与历史事实，为每个issue_id返回且仅返回一项。'
            . '返回{document_hash,shot_hash,locations:[{issue_id,status:located或uncertain,reason,anchor_unit_id,anchor_hash,position:before或after,evidence:[{unit,start,end,quote}]}]}。'
            . 'located仅允许allowed_anchor_unit_ids中的一个既有单元，保留已有场次与人物，说明此位置如何承接前后文且不改变人物认知或事件先后。'
            . 'evidence必须逐字引用完整锚点单元，以及插入侧在同一场次中的紧邻完整单元（若位于场次首尾则只需锚点）。'
            . '字符范围从0开始、end不含末位，按Unicode码点计数。无法确定安全位置或需要新场次/新人物时用uncertain，仅返回issue_id、status、reason，不猜位置。'
            . GenerationPipeline::historyInstruction($context),
            static fn($reply) => self::validate($document, $shots, $issues, $anchors, $reply));
        $trace = ['schema_version' => self::VERSION, 'stage_key' => $key, 'document_hash' => $document['content_hash'],
            'shot_hash' => $shots['content_hash'], 'report_hash' => ContentDocument::hash($report), 'locations' => $locations];
        $trace['location_hash'] = ContentDocument::hash($trace);
        foreach ($locations as $location) if ($location['status'] !== 'located') return ['report' => null, 'trace' => $trace];
        $byIssue = array_column($locations, null, 'issue_id');
        foreach ($report['issues'] as &$issue) if (isset($byIssue[$issue['issue_id']])) {
            $location = $byIssue[$issue['issue_id']];
            $issue['repair_unit_ids'] = [$location['anchor_unit_id']];
            $issue['insertion_location'] = $location + ['location_hash' => $trace['location_hash'], 'stage_key' => $key,
                'document_hash' => $document['content_hash'], 'shot_hash' => $shots['content_hash']];
            // Original issue ID, missing status, and original (possibly empty) evidence stay unchanged.
        }
        unset($issue);
        return ['report' => $report, 'trace' => $trace];
    }

    public static function validate(array $document, array $shots, array $issues, array $anchors, array $reply): array
    {
        if (array_diff(array_keys($reply), ['document_hash', 'shot_hash', 'locations'])
            || ($reply['document_hash'] ?? '') !== $document['content_hash'] || ($reply['shot_hash'] ?? '') !== $shots['content_hash']) {
            throw new RuntimeException('定位结果引用了错误的正文或分镜版本', 422);
        }
        $rows = ContentDocument::rows($reply['locations'] ?? null, 'locations'); $seen = [];
        $expected = array_column($issues, 'issue_id'); $units = ContentDocument::units($document);
        foreach ($rows as $row) {
            $id = $row['issue_id'] ?? null;
            if (!is_string($id) || !in_array($id, $expected, true) || isset($seen[$id])) throw new RuntimeException('定位问题标识无效或重复', 422);
            $seen[$id] = true; ContentDocument::text($row['reason'] ?? null, 'location.reason');
            if (($row['status'] ?? '') === 'uncertain') {
                if (array_diff(array_keys($row), ['issue_id', 'status', 'reason'])) throw new RuntimeException('不确定定位不能指定补入位置', 422);
                continue;
            }
            if (($row['status'] ?? '') !== 'located' || array_diff(array_keys($row), ['issue_id', 'status', 'reason', 'anchor_unit_id', 'anchor_hash', 'position', 'evidence'])) throw new RuntimeException('定位结论无效', 422);
            $anchor = $row['anchor_unit_id'] ?? null; $position = $row['position'] ?? '';
            if (!is_string($anchor) || !in_array($anchor, $anchors, true) || !isset($units[$anchor])
                || ($row['anchor_hash'] ?? '') !== $units[$anchor]['text_hash'] || !in_array($position, ['before', 'after'], true)) throw new RuntimeException('定位锚点或方向无效', 422);
            $required = [$units[$anchor]['ref']];
            foreach ($document['scenes'] as $scene) {
                $index = array_search($anchor, array_column($scene['units'], 'unit_id'), true);
                if ($index === false) continue;
                $neighbor = $index + ($position === 'before' ? -1 : 1);
                if (isset($scene['units'][$neighbor])) $required[] = $scene['units'][$neighbor]['ref'];
                break;
            }
            $evidence = ContentDocument::rows($row['evidence'] ?? null, 'location.evidence'); $quoted = [];
            $byRef = array_column($units, null, 'ref');
            foreach ($evidence as $link) {
                $ref = $link['unit'] ?? null;
                if (!is_string($ref) || !in_array($ref, $required, true) || isset($quoted[$ref])
                    || array_diff(array_keys($link), ['unit', 'start', 'end', 'quote'])) throw new RuntimeException('定位证据须引用锚点与插入侧相邻单元', 422);
                $unit = $byRef[$ref];
                if (($link['start'] ?? null) !== 0 || ($link['end'] ?? null) !== mb_strlen($unit['text'], 'UTF-8')
                    || ($link['quote'] ?? null) !== $unit['text']) throw new RuntimeException('定位证据须逐字保留完整单元', 422);
                $quoted[$ref] = true;
            }
            if (array_diff($required, array_keys($quoted))) throw new RuntimeException('定位遗漏插入边界原文', 422);
        }
        if (array_diff($expected, array_keys($seen))) throw new RuntimeException('定位遗漏待处理问题', 422);
        return $rows;
    }
}
