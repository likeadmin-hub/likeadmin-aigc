<?php
namespace app\common\service\app\aigc_short_drama\vnext;

use RuntimeException;

/** Repair visual fields only; immutable text/references make unaffected reviews reusable. */
final class SceneShotRepair
{
    public const VERSION = 1;

    public static function run(array $context, array $best, callable $call, callable $reserve, callable $checkpoint): array
    {
        for ($round = 1; $round <= 2 && $best['coverage_report']['status'] !== 'ready'; $round++) {
            $document = $best['document']; $base = $best['shot_plan']; $report = $best['coverage_report'];
            if (($report['window_review']['schema_version'] ?? 0) !== SceneReviewExecution::VERSION) {
                return $best + ['stop_reason' => 'windowed_content_requires_revision'];
            }
            $reviews = $report['window_review']['windows'];
            $plan = self::plan($document, $base, $reviews);
            if ($plan['plan_hash'] !== $report['window_review']['plan_hash'] || $report['document_hash'] !== $document['content_hash']
                || $report['shot_hash'] !== $base['content_hash']) throw new RuntimeException('窗口修复的基础版本已变化', 409);
            $affected = self::affected($report, $reviews);
            if (!$affected) return $best + ['stop_reason' => 'windowed_content_requires_revision'];
            $reservation = 'window_repair_' . $round;
            try { $reserve($reservation, count($affected) * 2 + 1); }
            catch (RuntimeException $error) {
                if ($error->getCode() !== 429) throw $error;
                return $best + ['stop_reason' => 'budget_exhausted'];
            }
            $patched = array_column($base['shots'], null, 'shot_id');
            $trace = ['schema_version' => self::VERSION, 'round' => $round, 'base_shot_hash' => $base['content_hash'],
                'review_plan_hash' => $plan['plan_hash'], 'windows' => array_map(static fn($index) => $index + 1, $affected),
                'reserved_calls' => count($affected) * 2 + 1, 'completed_patches' => 0, 'completed_reviews' => 0];
            foreach ($affected as $index) {
                $data = SceneReviewExecution::windowData($context, $document, $base, $plan['windows'][$index], $index + 1, count($reviews));
                $data['repair_issues'] = array_values(array_filter($reviews[$index]['checks'], static fn($check) => $check['id'] === 'aspect:shot_fidelity' && $check['status'] === 'contradiction'));
                $data['repair_targets'] = array_map(static fn($shot) => ['shot_id' => $shot['shot_id'], 'before_hash' => ContentDocument::hash($shot)], $data['shot_plan']['shots']);
                $changes = $call($reservation . '_patch_' . ($index + 1), $data,
                    '只修复repair_issues定位的分镜画面错误，正文与人物身份已经确定。返回{"patches":[{"shot_id":"已有镜头ID","before_hash":"repair_targets提供的哈希",'
                    . '"visual_description":"修复后完整画面描述","composition":"构图","camera_movement":"运镜"}]}。'
                    . '只修改当前窗口已有镜头的画面描述、构图与运镜，不润色无关镜头。不新增或删除镜头，不修改对白、说话人、主体、正文引用及顺序。'
                    . '不返回其他字段；无可行修改时patches为空。',
                    static fn($reply) => self::patches($data['shot_plan']['shots'], $reply), $reservation);
                foreach ($changes as $id => $shot) $patched[$id] = $shot;
                $trace['completed_patches']++;
                $checkpoint($best + ['shot_repair' => $trace + ['candidate_shots' => array_values($patched)]]);
            }
            if (array_values($patched) === $base['shots']) return $best + ['stop_reason' => 'unchanged_shots'];
            $candidate = self::compile($document, $base, array_values($patched), $document['revision_id'] . '_' . $reservation);
            $candidatePlan = self::plan($document, $candidate, $reviews);
            foreach ($reviews as $index => &$review) {
                $data = SceneReviewExecution::windowData($context, $document, $candidate, $candidatePlan['windows'][$index], $index + 1, count($reviews));
                if ($review['window'] !== $data['review_window']) throw new RuntimeException('修复改变了窗口正文或镜头范围', 409);
                if (in_array($index, $affected, true)) {
                    $checked = $call($reservation . '_review_' . ($index + 1), $data, SceneReviewExecution::windowInstruction($context),
                        static fn($reply) => SceneReviewExecution::validateWindow($context, $data, $reply), $reservation);
                    $review = ['window' => $data['review_window'], 'checks' => $checked['checks'], 'narrative_changes' => $checked['narrative_changes']];
                    $trace['completed_reviews']++;
                } else {
                    // Reuse only if the entire reviewed shot subset is byte-for-byte unchanged.
                    $old = SceneReviewExecution::windowData($context, $document, $base, $candidatePlan['windows'][$index], $index + 1, count($reviews));
                    if ($old['shot_plan']['shots'] !== $data['shot_plan']['shots']) throw new RuntimeException('未复核窗口的镜头已变化', 409);
                    SceneReviewExecution::validateWindow($context, $data, ['document_hash' => $document['content_hash'], 'shot_hash' => $candidate['content_hash'],
                        'checks' => $review['checks'], 'narrative_changes' => $review['narrative_changes']]);
                    $review['reused_from_shot_hash'] = $review['reused_from_shot_hash'] ?? $base['content_hash'];
                }
                $checkpoint($best + ['shot_repair' => $trace + ['candidate_shot_plan' => $candidate, 'reviews' => $reviews]]);
            }
            unset($review);
            $candidateReport = SceneReviewExecution::globalReview($context, $document, $candidate, $reviews, $candidatePlan, $call, $reservation . '_global', $reservation);
            // Never hide new defects behind a smaller count or replace the best draft with a regression.
            $before = self::blocking($report); $after = self::blocking($candidateReport);
            $oldConcerns = self::concerns($report['window_review']['windows']); $newConcerns = self::concerns($reviews);
            if (array_diff($after, $before) || array_diff($newConcerns, $oldConcerns)
                || ($candidateReport['status'] !== 'ready' && count($newConcerns) >= count($oldConcerns))) {
                $checkpoint($best + ['shot_repair' => $trace + ['candidate_shot_plan' => $candidate, 'candidate_report' => $candidateReport]]);
                return $best + ['stop_reason' => 'repair_not_improved'];
            }
            $candidateReport['shot_repair'] = ['round' => $round, 'base_shot_hash' => $base['content_hash'], 'windows' => $trace['windows']];
            $best = ['document' => $document, 'shot_plan' => $candidate, 'coverage_report' => $candidateReport];
            $checkpoint($best);
        }
        return $best['coverage_report']['status'] === 'ready' ? $best : $best + ['stop_reason' => 'windowed_content_requires_revision'];
    }

    private static function plan(array $document, array $shots, array $reviews): array
    {
        $plan = ['schema_version' => SceneReviewExecution::VERSION, 'document_hash' => $document['content_hash'],
            'shot_hash' => $shots['content_hash'], 'windows' => array_column(array_column($reviews, 'window'), 'shot_ids')];
        $plan['plan_hash'] = ContentDocument::hash($plan);
        SceneReviewExecution::assertPlan($document, $shots, $plan);
        return $plan;
    }

    private static function affected(array $report, array $reviews): array
    {
        $affected = []; $units = [];
        foreach ($reviews as $index => $review) foreach ($review['checks'] as $check) {
            if ($check['id'] === 'aspect:shot_fidelity' && $check['status'] === 'contradiction' && !empty($check['evidence'])) {
                $affected[] = $index; $units = array_merge($units, $review['window']['unit_ids'], $review['window']['boundary_unit_ids']);
            }
        }
        foreach ($report['issues'] as $issue) if ($issue['severity'] === 'blocking') {
            if ($issue['target_id'] !== 'aspect:shot_fidelity' || $issue['code'] !== 'content_contradiction'
                || empty($issue['repair_unit_ids']) || array_diff($issue['repair_unit_ids'], $units)) return [];
        }
        return $affected;
    }

    public static function patches(array $shots, array $reply): array
    {
        if (!array_key_exists('patches', $reply) || array_diff(array_keys($reply), ['patches'])) throw new RuntimeException('窗口修复必须只返回分镜补丁', 422);
        $known = array_column($shots, null, 'shot_id'); $changes = [];
        foreach (ContentDocument::rows($reply['patches'], 'shot.patches') as $patch) {
            $id = $patch['shot_id'] ?? '';
            if (!is_string($id) || !isset($known[$id]) || isset($changes[$id])) throw new RuntimeException('修复引用了窗口外或重复的镜头', 422);
            if (($patch['before_hash'] ?? '') !== ContentDocument::hash($known[$id])) throw new RuntimeException('分镜修复的原始哈希不符', 422);
            if (array_diff(array_keys($patch), ['shot_id', 'before_hash', 'visual_description', 'composition', 'camera_movement'])) throw new RuntimeException('修复不能改变镜头身份、正文引用或对白', 422);
            $shot = $known[$id]; $shot['visual_description'] = ContentDocument::text($patch['visual_description'] ?? null, 'visual_description');
            foreach (['composition', 'camera_movement'] as $field) if (array_key_exists($field, $patch)) {
                if (!is_string($patch[$field])) throw new RuntimeException('分镜画面字段格式无效', 422);
                $shot[$field] = $patch[$field];
            }
            $changes[$id] = $shot;
        }
        return $changes;
    }

    private static function compile(array $document, array $base, array $shots, string $revision): array
    {
        $refs = array_column(ContentDocument::units($document), 'ref', 'unit_id'); $raw = [];
        foreach ($shots as $shot) {
            $links = [];
            foreach ($shot['unit_refs'] as $ref) $links[] = ['unit' => $refs[$ref['unit_id']], 'start' => $ref['start'], 'end' => $ref['end']];
            $raw[] = ['visual_description' => $shot['visual_description'], 'composition' => $shot['composition'], 'camera_movement' => $shot['camera_movement'], 'refs' => $links];
        }
        $compiled = ShotCompiler::compile($document, ['shots' => $raw], $revision);
        // Keep stable shot identity and original per-shot revision for untouched shots.
        foreach ($shots as $index => &$shot) if ($shot !== $base['shots'][$index]) $shot['revision_id'] = $revision;
        unset($shot);
        $compiled['shots'] = $shots;
        if ($compiled['audio_segments'] !== $base['audio_segments']) throw new RuntimeException('画面修复意外改变了声音段', 409);
        unset($compiled['content_hash']); $compiled['content_hash'] = ContentDocument::hash($compiled);
        return $compiled;
    }

    private static function blocking(array $report): array
    {
        return array_values(array_map(static fn($issue) => $issue['target_id'] . ':' . $issue['code'],
            array_filter($report['issues'], static fn($issue) => $issue['severity'] === 'blocking')));
    }
    private static function concerns(array $reviews): array
    {
        $out = [];
        foreach ($reviews as $index => $review) foreach ($review['checks'] as $check) {
            if (in_array($check['status'], ['contradiction', 'uncertain'], true)) $out[] = $index . ':' . $check['id'] . ':' . $check['status'];
        }
        return $out;
    }
}
