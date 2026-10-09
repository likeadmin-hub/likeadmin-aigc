<?php
namespace app\common\service\app\aigc_short_drama\vnext;

use RuntimeException;
use app\common\service\app\aigc_short_drama\ShortDramaOutputLimitException;

/** Local observations never stand in for acceptance of the whole episode. */
final class SceneReviewExecution
{
    public const VERSION = 1;

    public static function run(array $context, array $document, array $shots, callable $call, callable $checkpoint, array $execution, int $projection, bool &$windowed): array
    {
        $plan = ($execution['review_plan'])($document, $shots, null);
        if ($plan === null) {
            $data = GenerationPipeline::reviewData($context, $document, $shots);
            $instruction = GenerationPipeline::reviewInstruction($context);
            $truncated = false;
            if (self::fits($data, $instruction, $execution['capacity'], $projection)) {
                try {
                    return $call('review', $data, $instruction,
                        static fn($reply) => GenerationPipeline::validateReview($context, $document, $shots, $reply));
                } catch (ShortDramaOutputLimitException $error) { $truncated = true; }
            }
            $proposal = self::partition($context, $document, $shots, $execution['capacity'], $projection, $truncated);
            $plan = ($execution['review_plan'])($document, $shots, $proposal);
        }
        self::assertPlan($document, $shots, $plan);
        $windowed = true; $reviews = [];
        foreach ($plan['windows'] as $index => $ids) {
            $data = self::windowData($context, $document, $shots, $ids, $index + 1, count($plan['windows']));
            $review = $call('review_window_' . ($index + 1), $data, self::windowInstruction($context),
                static fn($reply) => self::validateWindow($context, $data, $reply));
            $reviews[] = ['window' => $data['review_window'], 'checks' => $review['checks'], 'narrative_changes' => $review['narrative_changes']];
            $checkpoint(['document' => $document, 'shot_plan' => $shots,
                'review_windows' => ['plan_hash' => $plan['plan_hash'], 'completed' => $index + 1, 'total' => count($plan['windows']), 'reviews' => $reviews]]);
        }
        $data = self::globalData($context, $document, $shots, $reviews);
        return $call('review_global', $data, GenerationPipeline::reviewInstruction($context)
            . '本次为分窗核验后的整集合并检查。document只展示已核对的证据单元与各窗口边界，不是全文；window_reviews覆盖全部分镜和正文。'
            . '综合每个窗口的逐项观察、原文证据和状态变化，逐项核对整集核心事件、复合要求全部部分、人物身份及跨窗口因果/时间/认知衔接。'
            . 'not_observed只代表该窗口未观察到，不是整集缺失；其他窗口出现可补足。covered只代表局部证据，不自动证明整条要求满足。'
            . '窗口contradiction或uncertain不能被忽略并改成covered；无法确定全局结论时保留uncertain。只引用当前展示的真实正文，不用观察说明代替证据。'
            . '综合全部narrative_changes给出本集结束状态，每个已观察主体/属性/知情者都必须有结论；无法确定最终状态时用uncertain，闪回不得覆盖现在。',
            static function ($reply) use ($context, $document, $shots, $data, $reviews, $plan): array {
                self::validateGlobal($context, $data, $reviews, $reply);
                $report = GenerationPipeline::validateReview($context, $document, $shots, $reply);
                $report['window_review'] = ['schema_version' => self::VERSION, 'plan_hash' => $plan['plan_hash'],
                    'windows' => $reviews, 'global_review' => $reply];
                return $report;
            });
    }

    public static function partition(array $context, array $document, array $shots, callable $capacity, int $projection, bool $afterTruncation = false): array
    {
        ShotCompiler::assertCurrent($document, $shots);
        $ids = array_column($shots['shots'], 'shot_id');
        if (!$ids || ($afterTruncation && count($ids) < 2)) throw new RuntimeException('单个完整分镜仍超出核验容量，原文与分镜保留', 413);
        $limit = $afterTruncation ? (int)ceil(count($ids) / 2) : count($ids);
        $windows = []; $current = [];
        foreach ($ids as $id) {
            $trial = array_merge($current, [$id]);
            if (count($trial) > $limit || !self::fits(self::windowData($context, $document, $shots, $trial, 999, 999), self::windowInstruction($context), $capacity, $projection)) {
                if (!$current) throw new RuntimeException('单个分镜及其完整正文无法容纳核验请求', 413);
                $windows[] = $current; $current = [$id];
                if (count($windows) >= 32) throw new RuntimeException('核验窗口超过执行上限，已有内容保留', 413);
                if (!self::fits(self::windowData($context, $document, $shots, $current, 999, 999), self::windowInstruction($context), $capacity, $projection)) {
                    throw new RuntimeException('单个分镜及其完整正文无法容纳核验请求', 413);
                }
            } else $current = $trial;
        }
        if ($current) $windows[] = $current;
        $plan = ['schema_version' => self::VERSION, 'document_hash' => $document['content_hash'], 'shot_hash' => $shots['content_hash'], 'windows' => $windows];
        $plan['plan_hash'] = ContentDocument::hash($plan);
        return $plan;
    }

    public static function assertPlan(array $document, array $shots, array $plan): void
    {
        $unsigned = $plan; unset($unsigned['plan_hash']);
        if (($plan['schema_version'] ?? 0) !== self::VERSION || ($plan['document_hash'] ?? '') !== $document['content_hash']
            || ($plan['shot_hash'] ?? '') !== $shots['content_hash'] || ($plan['plan_hash'] ?? '') !== ContentDocument::hash($unsigned)
            || !is_array($plan['windows'] ?? null) || !$plan['windows'] || array_values($plan['windows']) !== $plan['windows'] || count($plan['windows']) > 32) {
            throw new RuntimeException('核验窗口计划已失效', 409);
        }
        $all = [];
        foreach ($plan['windows'] as $ids) {
            if (!is_array($ids) || !$ids || array_values($ids) !== $ids) throw new RuntimeException('核验窗口计划不完整', 409);
            $all = array_merge($all, $ids);
        }
        if ($all !== array_column($shots['shots'], 'shot_id')) throw new RuntimeException('核验窗口遗漏、重复或重排分镜', 409);
    }

    private static function fits(array $data, string $instruction, callable $capacity, int $projection): bool
    {
        try { $capacity(GenerationPipeline::messages($data, $instruction, $projection)); return true; }
        catch (RuntimeException $error) { if ($error->getCode() !== 413) throw $error; return false; }
    }

    private static function targets(array $context, array $document): array
    {
        $targets = [];
        foreach (array_merge($context['requirements'] ?? [], $context['events'] ?? []) as $item) {
            if (!empty($item['episode_ids']) && !in_array($document['episode_id'], $item['episode_ids'], true)) continue;
            $id = ContentDocument::text($item['id'] ?? null, 'check.id');
            if (isset($targets[$id])) throw new RuntimeException('确认范围存在重复标识', 422);
            $targets[$id] = $item;
        }
        foreach (['identity', 'continuity', 'shot_fidelity'] as $aspect) {
            $id = 'aspect:' . $aspect;
            if (isset($targets[$id])) throw new RuntimeException('确认范围占用了核验标识', 422);
            $targets[$id] = ['id' => $id];
        }
        return $targets;
    }

    private static function windowInstruction(array $context): string
    {
        return '核验当前分镜窗口的完整正文、人物、连续性、分镜一致性与每项适用本集的requirements/events。'
            . '返回{document_hash,shot_hash,checks:[{id,status:covered或not_observed或contradiction或uncertain,scope:"window",reason,evidence:[{unit,start,end,quote}]}]}。'
            . '哈希沿用输入完整版本。逐项返回所有适用要求/事件和aspect:identity、aspect:continuity、aspect:shot_fidelity，不遗漏。'
            . 'covered表示本窗口有相关证据，说明满足了哪些部分，不能声称已满足整集复合要求；没有相关事件用not_observed，不能用missing推断整集缺失。'
            . '三个aspect不得用not_observed。所有covered/contradiction必须给当前正文真实引文；引文按Unicode码点且end不包含。'
            . '逐项reason说明实际观察及前后因果，不编造窗口外的事实。边界相邻单元仅供衔接核对，不要求其在当前分镜中出现。'
            . '修改意见的非covered结论需issue_kind:content或presentation；纯措辞、风格建议属于presentation。'
            . ((int)($context['narrative_contract_version'] ?? 0) === NarrativeLedger::VERSION ? NarrativeLedger::instruction() : '');
    }

    public static function windowData(array $context, array $document, array $shots, array $ids, int $number, int $total): array
    {
        $selected = array_fill_keys($ids, true); $view = $shots;
        $view['shots'] = array_values(array_filter($shots['shots'], static fn($shot) => isset($selected[$shot['shot_id']])));
        $unitIds = [];
        foreach ($view['shots'] as $shot) foreach ($shot['unit_refs'] as $ref) $unitIds[$ref['unit_id']] = true;
        $all = array_keys(ContentDocument::units($document)); $positions = array_keys(array_intersect($all, array_keys($unitIds)));
        if (!$positions) throw new RuntimeException('核验窗口没有正文', 409);
        $first = min($positions); $last = max($positions);
        $boundary = array_values(array_unique([$all[max(0, $first - 1)], $all[$first], $all[$last], $all[min(count($all) - 1, $last + 1)]]));
        $view['audio_segments'] = array_values(array_filter($shots['audio_segments'], static fn($audio) => isset($unitIds[$audio['unit_id']])));
        $data = GenerationPipeline::reviewData($context, SceneShotExecution::view($document, array_merge(array_keys($unitIds), $boundary)), $view);
        $data['review_window'] = ['number' => $number, 'total' => $total, 'shot_ids' => $ids, 'unit_ids' => array_keys($unitIds), 'boundary_unit_ids' => $boundary];
        return $data;
    }

    public static function validateWindow(array $context, array $data, array $reply): array
    {
        if (($reply['document_hash'] ?? '') !== $data['document']['content_hash'] || ($reply['shot_hash'] ?? '') !== $data['shot_plan']['content_hash']) {
            throw new RuntimeException('窗口核验引用过期版本', 409);
        }
        $targets = self::targets($context, $data['document']); $checks = [];
        foreach (ContentDocument::rows($reply['checks'] ?? [], 'window.checks') as $check) {
            $id = $check['id'] ?? '';
            if (!is_string($id) || !isset($targets[$id]) || isset($checks[$id])) throw new RuntimeException('窗口检查项未知或重复', 422);
            $status = $check['status'] ?? '';
            if (!in_array($status, ['covered', 'not_observed', 'contradiction', 'uncertain'], true) || ($check['scope'] ?? '') !== 'window'
                || (str_starts_with($id, 'aspect:') && $status === 'not_observed')) throw new RuntimeException('窗口核验范围或结论无效', 422);
            ContentDocument::text($check['reason'] ?? null, 'window.reason');
            self::evidence($data['document'], $check['evidence'] ?? []);
            if (in_array($status, ['covered', 'contradiction'], true) && empty($check['evidence'])) throw new RuntimeException('窗口结论缺少正文证据', 422);
            if (($targets[$id]['kind'] ?? '') === 'revision_instructions' && $status !== 'covered'
                && !in_array($check['issue_kind'] ?? '', ['content', 'presentation'], true)) throw new RuntimeException('窗口修改意见缺少问题分类', 422);
            $checks[$id] = $check;
        }
        if (array_diff_key($targets, $checks)) throw new RuntimeException('窗口遗漏适用的检查项', 422);
        $changes = [];
        if ((int)($context['narrative_contract_version'] ?? 0) === NarrativeLedger::VERSION) {
            if (!array_key_exists('narrative_changes', $reply)) throw new RuntimeException('窗口缺少事实与认知观察', 422);
            $changes = ContentDocument::rows($reply['narrative_changes'], 'narrative_changes');
            NarrativeLedger::evaluate($data['document'], $changes);
        }
        return ['checks' => array_values($checks), 'narrative_changes' => $changes];
    }

    private static function evidence(array $document, $evidence): void
    {
        $refs = array_column(ContentDocument::units($document), null, 'ref');
        foreach (ContentDocument::rows($evidence, 'review.evidence') as $link) {
            $unit = $refs[$link['unit'] ?? ''] ?? null;
            if (!$unit || !isset($link['quote'])) throw new RuntimeException('核验引用当前窗口外正文或缺少引文', 422);
            ContentDocument::slice($unit['text'], $link['start'] ?? null, $link['end'] ?? null, $link['quote']);
        }
    }

    private static function globalData(array $context, array $document, array $shots, array $reviews): array
    {
        $refs = array_column(ContentDocument::units($document), 'unit_id', 'ref'); $ids = [];
        foreach ($reviews as $review) {
            $ids = array_merge($ids, $review['window']['boundary_unit_ids']);
            foreach (array_merge($review['checks'], $review['narrative_changes']) as $row) {
                foreach ($row['evidence'] ?? [] as $link) $ids[] = $refs[$link['unit']];
            }
        }
        $data = GenerationPipeline::reviewData($context, SceneShotExecution::view($document, array_values(array_unique($ids))), $shots);
        $data['shot_plan'] = array_intersect_key($shots, array_flip(['revision_id', 'document_revision_id', 'document_hash', 'content_hash']))
            + ['shots' => [], 'audio_segments' => [], 'scope' => 'verified_in_window_reviews'];
        $data['window_reviews'] = $reviews;
        return $data;
    }

    private static function validateGlobal(array $context, array $data, array $reviews, array $reply): void
    {
        $checks = ContentDocument::rows($reply['checks'] ?? [], 'global.checks');
        foreach ($checks as $check) {
            if (($check['scope'] ?? '') !== 'whole_episode') throw new RuntimeException('整集合并核验必须明确覆盖全局', 422);
            self::evidence($data['document'], $check['evidence'] ?? []);
            foreach ($reviews as $review) foreach ($review['checks'] as $local) {
                if ($local['id'] === ($check['id'] ?? '') && ($local['issue_kind'] ?? '') === 'content'
                    && in_array($local['status'], ['contradiction', 'uncertain'], true) && ($check['issue_kind'] ?? '') === 'presentation') {
                    throw new RuntimeException('整集核验不能把窗口内容问题降级为表达建议', 422);
                }
            }
            if (($check['status'] ?? '') !== 'covered') continue;
            $observed = false;
            foreach ($reviews as $review) foreach ($review['checks'] as $local) {
                if ($local['id'] !== ($check['id'] ?? '')) continue;
                if (in_array($local['status'], ['contradiction', 'uncertain'], true)) throw new RuntimeException('整集结论不能忽略未解决的窗口问题', 422);
                $observed = $observed || $local['status'] === 'covered';
            }
            if (!$observed) throw new RuntimeException('整集覆盖结论缺少窗口观察', 422);
        }
        if ((int)($context['narrative_contract_version'] ?? 0) === NarrativeLedger::VERSION) {
            $key = static fn($row) => ContentDocument::hash([$row['kind'] ?? '', $row['subject_id'] ?? '', $row['predicate'] ?? '', $row['observer_id'] ?? null]);
            $final = [];
            foreach (ContentDocument::rows($reply['narrative_changes'] ?? [], 'narrative_changes') as $change) {
                self::evidence($data['document'], $change['evidence'] ?? []); $final[$key($change)] = true;
            }
            foreach ($reviews as $review) foreach ($review['narrative_changes'] as $change) {
                if (!isset($final[$key($change)])) throw new RuntimeException('整集核验遗漏已观察到的事实或认知变化', 422);
            }
        }
    }
}
