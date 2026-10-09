<?php
namespace app\common\service\app\aigc_short_drama\vnext;

use RuntimeException;

/** Text changes invalidate shot references and boundary observations before acceptance. */
final class SceneContentRepair
{
    public const VERSION = 1;

    public static function run(array $context, array $best, callable $call, callable $reserve, callable $checkpoint): array
    {
        if (isset($context['canonical_input']) || isset($context['structured_screenplay']) || ($context['source_mode'] ?? '') === 'faithful') {
            return $best + ['stop_reason' => 'source_content_requires_confirmation'];
        }
        for ($round = 1; $round <= 2 && $best['coverage_report']['status'] !== 'ready'; $round++) {
            $base = $best['document']; $shots = $best['shot_plan']; $report = $best['coverage_report'];
            $scope = self::scope($base, $shots, $report);
            if ($scope === null && ($context['missing_content_location_version'] ?? 0) === MissingContentLocation::VERSION) {
                $located = MissingContentLocation::run($context, $base, $shots, $report, $call, 'content_window_location_' . $round);
                if ($located !== null) {
                    $best['missing_location'] = $located['trace']; $checkpoint($best);
                    if ($located['report'] === null) return $best + ['stop_reason' => 'missing_location_unresolved'];
                    $report = $located['report']; $scope = self::scope($base, $shots, $report);
                }
            }
            if ($scope === null) return $best + ['stop_reason' => 'windowed_content_requires_revision'];
            $key = 'content_window_repair_' . $round;
            $calls = 2 + count($scope['replan']) + count($scope['recheck']);
            try { $reserve($key, $calls); }
            catch (RuntimeException $error) { if ($error->getCode() !== 429) throw $error; return $best + ['stop_reason' => 'budget_exhausted']; }
            $ids = array_values(array_unique(array_merge($scope['patch_ids'], array_keys($scope['anchor_owners']))));
            $data = GenerationPipeline::reviewData($context, SceneShotExecution::view($base, $ids), $shots);
            unset($data['shot_plan']);
            $data['repair_issues'] = $scope['issues']; $data['allowed_patch_unit_ids'] = $scope['patch_ids'];
            $data['allowed_insertion_anchors'] = $scope['anchors'];
            if ($scope['positions']) $data['allowed_insertion_positions'] = $scope['positions'];
            $revision = $context['revision_id'] . '_cw' . $round;
            $change = $call($key . '_text', $data,
                '只修复repair_issues已确认的正文遗漏或矛盾，不改写无关单元。返回{patches:[{unit_id,before_hash,text}],insertions:[{issue_id,anchor_unit_id,anchor_hash,position:before或after,type:action或dialogue,text,entity_ids:[已有主体ID],speaker_id:已有人物ID或null,speech_type:spoken或voiceover或narration}]}。'
                . 'patches只能修改allowed_patch_unit_ids的完整正文；不删除、重排或改变说话人。insertions只能对应allowed_insertion_anchors中的缺失问题与锚点，同一问题只选一个锚点，可补多个单元。'
                . ($scope['positions'] ? 'allowed_insertion_positions指定的补入方向必须逐项遵守，不移动至锚点另一侧。' : '')
                . '保留既有角色和场次，不创造未登记身份；不能修复则返回空列表。不把编辑指令写成剧情。' . GenerationPipeline::historyInstruction($context),
                static fn($reply) => self::apply($base, $scope, $reply, $revision), $key);
            $candidate = $change['document'];
            if (self::textState($base) === self::textState($candidate)) return $best + ['stop_reason' => 'unchanged_content'];
            $trace = ['schema_version' => self::VERSION, 'round' => $round, 'base_document_hash' => $base['content_hash'], 'base_shot_hash' => $shots['content_hash'],
                'reserved_calls' => $calls, 'replan_windows' => $scope['replan'], 'recheck_windows' => $scope['recheck'], 'candidate_document' => $candidate];
            $checkpoint($best + ['content_repair' => $trace]);
            $oldUnits = ContentDocument::units($base); $newUnits = ContentDocument::units($candidate);
            $groups = []; $compiledWindows = [];
            foreach ($scope['reviews'] as $index => $review) {
                $units = $review['window']['unit_ids'];
                foreach ($newUnits as $id => $unit) if (!isset($oldUnits[$id]) && $change['owners'][$id] === $index) $units[] = $id;
                $view = SceneShotExecution::view($candidate, $units);
                if (in_array($index, $scope['replan'], true)) {
                    $part = $call($key . '_shots_' . ($index + 1), ['document' => $view, 'repair_window' => ['number' => $index + 1, 'total' => count($scope['reviews'])]],
                        GenerationPipeline::shotInstruction() . '只编排当前修复窗口，保留输入的全局ref，不补写窗口外正文。',
                        static fn($reply) => ShotCompiler::compile($view, $reply, $revision . '_w' . ($index + 1)), $key);
                    $groups[$index] = $part['shots'];
                } else {
                    $oldData = SceneReviewExecution::windowData($context, $base, $shots, $review['window']['shot_ids'], $index + 1, count($scope['reviews']));
                    $groups[$index] = $oldData['shot_plan']['shots'];
                }
                $compiledWindows[] = $index + 1;
                $checkpoint($best + ['content_repair' => $trace + ['completed_shot_windows' => $compiledWindows, 'candidate_shot_groups' => $groups]]);
            }
            [$candidateShots, $plan] = self::compile($candidate, $groups, $revision . '_shots');
            $reviews = [];
            foreach ($scope['reviews'] as $index => $oldReview) {
                $view = SceneReviewExecution::windowData($context, $candidate, $candidateShots, $plan['windows'][$index], $index + 1, count($scope['reviews']));
                if (in_array($index, $scope['recheck'], true)) {
                    $checked = $call($key . '_review_' . ($index + 1), $view, SceneReviewExecution::windowInstruction($context),
                        static fn($reply) => SceneReviewExecution::validateWindow($context, $view, $reply), $key);
                    $reviews[] = ['window' => $view['review_window'], 'checks' => $checked['checks'], 'narrative_changes' => $checked['narrative_changes']];
                } else {
                    $oldView = SceneReviewExecution::windowData($context, $base, $shots, $oldReview['window']['shot_ids'], $index + 1, count($scope['reviews']));
                    if (self::viewState($oldView) !== self::viewState($view)) throw new RuntimeException('正文修复影响超出已预留复核范围，候选保留', 409);
                    $rebased = self::rebase($base, $candidate, $oldReview);
                    $rebased['window'] = $view['review_window'];
                    SceneReviewExecution::validateWindow($context, $view, ['document_hash' => $candidate['content_hash'], 'shot_hash' => $candidateShots['content_hash'],
                        'checks' => $rebased['checks'], 'narrative_changes' => $rebased['narrative_changes']]);
                    $rebased['reuse_chain'][] = ['document_hash' => $base['content_hash'], 'shot_hash' => $shots['content_hash'], 'window_number' => $index + 1];
                    $reviews[] = $rebased;
                }
                $checkpoint($best + ['content_repair' => $trace + ['candidate_shot_plan' => $candidateShots, 'reviews' => $reviews]]);
            }
            $nextReport = SceneReviewExecution::globalReview($context, $candidate, $candidateShots, $reviews, $plan, $call, $key . '_global', $key);
            $before = self::blocking($report); $after = self::blocking($nextReport);
            if (array_diff($after, $before) || count($after) >= count($before)) {
                $checkpoint($best + ['content_repair' => $trace + ['candidate_shot_plan' => $candidateShots, 'candidate_report' => $nextReport]]);
                return $best + ['stop_reason' => 'repair_not_improved'];
            }
            $changes = [];
            foreach ($newUnits as $id => $unit) if (!isset($oldUnits[$id]) || $oldUnits[$id]['text_hash'] !== $unit['text_hash']) {
                $changes[] = ['unit_id' => $id, 'before_text_hash' => $oldUnits[$id]['text_hash'] ?? null, 'after_text_hash' => $unit['text_hash']]
                    + (isset($change['insertion_anchors'][$id]) ? ['anchor_unit_id' => $change['insertion_anchors'][$id], 'issue_id' => $unit['repair_issue_id']] : []);
            }
            $entry = ['round' => $round, 'base_revision_id' => $base['revision_id'], 'base_document_hash' => $base['content_hash'],
                'revision_id' => $candidate['revision_id'], 'document_hash' => $candidate['content_hash'], 'issues' => $scope['issues'], 'changes' => $changes,
                'replan_windows' => $scope['replan'], 'recheck_windows' => $scope['recheck']];
            if (isset($best['missing_location'])) $entry['missing_location'] = $best['missing_location'];
            $nextReport['content_repair'] = $entry + ['history' => array_merge($report['content_repair']['history'] ?? [], [$entry])];
            $best = ['document' => $candidate, 'shot_plan' => $candidateShots, 'coverage_report' => $nextReport];
            $checkpoint($best);
        }
        return $best['coverage_report']['status'] === 'ready' ? $best : $best + ['stop_reason' => 'windowed_content_requires_revision'];
    }

    public static function baseline(array $document, array $shots, array $report): ?array
    {
        if (($report['window_review']['schema_version'] ?? 0) !== SceneReviewExecution::VERSION) return null;
        $reviews = $report['window_review']['windows'];
        $plan = ['schema_version' => SceneReviewExecution::VERSION, 'document_hash' => $document['content_hash'], 'shot_hash' => $shots['content_hash'],
            'windows' => array_column(array_column($reviews, 'window'), 'shot_ids')];
        $plan['plan_hash'] = ContentDocument::hash($plan);
        SceneReviewExecution::assertPlan($document, $shots, $plan);
        if ($plan['plan_hash'] !== $report['window_review']['plan_hash'] || $report['document_hash'] !== $document['content_hash'] || $report['shot_hash'] !== $shots['content_hash']) throw new RuntimeException('正文修复的核验基线已变化', 409);
        $owners = [];
        foreach ($reviews as $index => $review) {
            $actual = SceneReviewExecution::windowData([], $document, $shots, $review['window']['shot_ids'], $index + 1, count($reviews));
            if ($review['window'] !== $actual['review_window']) throw new RuntimeException('核验窗口的正文范围已变化', 409);
            foreach ($review['window']['unit_ids'] as $id) $owners[$id][] = $index;
        }
        return ['reviews' => $reviews, 'owners' => $owners];
    }

    public static function scope(array $document, array $shots, array $report): ?array
    {
        $baseline = self::baseline($document, $shots, $report);
        if ($baseline === null) return null;
        $reviews = $baseline['reviews']; $owners = $baseline['owners']; $units = ContentDocument::units($document);
        $issues = []; $patch = []; $anchors = []; $anchorOwners = []; $affected = []; $recheck = []; $positions = [];
        foreach ($report['issues'] as $issue) if ($issue['severity'] === 'blocking') {
            if ($issue['target_id'] === 'aspect:shot_fidelity' || !in_array($issue['code'], ['content_missing', 'content_contradiction'], true) || empty($issue['repair_unit_ids'])) return null;
            $issues[] = $issue;
            if (isset($issue['insertion_location'])) $positions[$issue['issue_id']] = $issue['insertion_location']['position'];
            foreach ($issue['repair_unit_ids'] as $id) {
                if (!isset($units[$id], $owners[$id])) return null;
                if ($issue['code'] === 'content_missing') {
                    // An insertion must have one unambiguous owning window.
                    if (count($owners[$id]) !== 1) return null;
                    $anchors[$issue['issue_id']][] = $id; $anchorOwners[$id] = $owners[$id][0];
                    foreach ($owners[$id] as $index) foreach ([$index - 1, $index, $index + 1] as $neighbor) if (isset($reviews[$neighbor])) $recheck[$neighbor] = true;
                } else $patch[$id] = true;
                foreach ($owners[$id] as $index) $affected[$index] = true;
                foreach ($reviews as $index => $review) if (in_array($id, array_merge($review['window']['unit_ids'], $review['window']['boundary_unit_ids']), true)) $recheck[$index] = true;
            }
        }
        if (!$issues) return null;
        $replan = array_keys($affected); sort($replan); $check = array_keys($recheck); sort($check);
        return ['issues' => $issues, 'patch_ids' => array_keys($patch), 'anchors' => $anchors, 'anchor_owners' => $anchorOwners,
            'replan' => $replan, 'recheck' => $check, 'reviews' => $reviews, 'positions' => $positions];
    }

    public static function apply(array $document, array $scope, array $reply, string $revision): array
    {
        if (!array_key_exists('patches', $reply) || array_diff(array_keys($reply), ['patches', 'insertions'])) throw new RuntimeException('正文修复必须返回限定补丁', 422);
        $patches = ContentDocument::rows($reply['patches'], 'patches'); $insertions = ContentDocument::rows($reply['insertions'] ?? [], 'insertions');
        $units = ContentDocument::units($document); $seen = []; $chosen = [];
        foreach ($patches as $patch) {
            $id = $patch['unit_id'] ?? '';
            if (!is_string($id) || !in_array($id, $scope['patch_ids'], true) || isset($seen[$id]) || ($patch['before_hash'] ?? '') !== $units[$id]['text_hash']) throw new RuntimeException('补丁超出核验定位、重复或原文哈希错误', 422);
            $seen[$id] = true;
        }
        foreach ($insertions as $insertion) {
            $issue = $insertion['issue_id'] ?? ''; $anchor = $insertion['anchor_unit_id'] ?? '';
            if (!is_string($issue) || !is_string($anchor) || !in_array($anchor, $scope['anchors'][$issue] ?? [], true)
                || ($insertion['anchor_hash'] ?? '') !== $units[$anchor]['text_hash'] || (isset($chosen[$issue]) && $chosen[$issue] !== $anchor)) throw new RuntimeException('补全必须使用同一已定位锚点及原文哈希', 422);
            if (isset($scope['positions'][$issue]) && ($insertion['position'] ?? '') !== $scope['positions'][$issue]) throw new RuntimeException('补入方向与定位证据不符', 422);
            $chosen[$issue] = $anchor;
        }
        $candidate = ContentDocument::repair($document, $patches, $insertions, $revision, $scope['issues']); $owners = []; $insertionAnchors = [];
        foreach (ContentDocument::units($candidate) as $id => $unit) if (!isset($units[$id])) {
            $insertionAnchors[$id] = $chosen[$unit['repair_issue_id']];
            $owners[$id] = $scope['anchor_owners'][$insertionAnchors[$id]];
        }
        return ['document' => $candidate, 'owners' => $owners, 'insertion_anchors' => $insertionAnchors];
    }

    private static function compile(array $document, array $groups, string $revision): array
    {
        $refs = array_column(ContentDocument::units($document), 'ref', 'unit_id'); $raw = []; $identities = []; $windows = [];
        foreach ($groups as $index => $shots) {
            $windows[$index] = [];
            foreach ($shots as $shot) {
                $links = [];
                foreach ($shot['unit_refs'] as $link) $links[] = ['unit' => $refs[$link['unit_id']], 'start' => $link['start'], 'end' => $link['end']];
                $raw[] = ['visual_description' => $shot['visual_description'], 'composition' => $shot['composition'], 'camera_movement' => $shot['camera_movement'], 'refs' => $links];
                $identities[] = ['shot_id' => $shot['shot_id'], 'revision_id' => $shot['revision_id']]; $windows[$index][] = $shot['shot_id'];
            }
        }
        $result = ShotCompiler::compile($document, ['shots' => $raw], $revision);
        foreach ($result['shots'] as $index => &$shot) $shot = array_merge($shot, $identities[$index]);
        unset($shot, $result['content_hash']); $result['content_hash'] = ContentDocument::hash($result);
        $plan = ['schema_version' => SceneReviewExecution::VERSION, 'document_hash' => $document['content_hash'], 'shot_hash' => $result['content_hash'], 'windows' => $windows];
        $plan['plan_hash'] = ContentDocument::hash($plan); SceneReviewExecution::assertPlan($document, $result, $plan);
        return [$result, $plan];
    }

    private static function viewState(array $data): string
    {
        $scenes = $data['document']['scenes']; $shots = $data['shot_plan']['shots'];
        foreach ($scenes as &$scene) foreach ($scene['units'] as &$unit) unset($unit['ref']);
        unset($scene, $unit);
        foreach ($shots as &$shot) unset($shot['order'], $shot['document_hash'], $shot['document_revision_id'], $shot['revision_id']);
        unset($shot);
        return ContentDocument::hash([$data['document']['entities'], $scenes, $shots]);
    }

    public static function rebase(array $before, array $after, array $review): array
    {
        $old = array_column(ContentDocument::units($before), null, 'ref'); $new = ContentDocument::units($after);
        foreach (['checks', 'narrative_changes'] as $field) foreach ($review[$field] as &$row) foreach ($row['evidence'] ?? [] as $index => $link) {
            $unit = $old[$link['unit'] ?? ''] ?? null; $current = $unit ? ($new[$unit['unit_id']] ?? null) : null;
            if (!$current || $unit['text_hash'] !== $current['text_hash'] || $unit['text'] !== $current['text']) throw new RuntimeException('变更正文的旧核验证据不能复用', 409);
            ContentDocument::slice($current['text'], $link['start'], $link['end'], $link['quote']);
            $row['evidence'][$index]['unit'] = $current['ref'];
        }
        unset($row);
        return $review;
    }
    private static function textState(array $document): string
    { return ContentDocument::hash(array_map(static fn($unit) => [$unit['unit_id'], $unit['text']], ContentDocument::units($document))); }
    private static function blocking(array $report): array
    { return array_values(array_map(static fn($issue) => $issue['target_id'] . ':' . $issue['code'], array_filter($report['issues'], static fn($issue) => $issue['severity'] === 'blocking'))); }
}
