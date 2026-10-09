<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\vnext\ContentDocument;
use app\common\service\app\aigc_short_drama\vnext\GenerationPipeline;
use app\common\service\app\aigc_short_drama\vnext\SceneReviewExecution;
use app\common\service\app\aigc_short_drama\vnext\ShotCompiler;
use PHPUnit\Framework\TestCase;

final class ShortDramaVnextSceneReviewTest extends TestCase
{
    public static function input(): array
    {
        $input = ShortDramaVnextSceneShotTest::input();
        $input['context']['scene_shot_version'] = 0;
        $input['context']['scene_review_version'] = 1;
        $input['context']['events'] = [['id' => 'ending', 'text' => '第6句完整对白😀。', 'blocking' => true]];
        return $input;
    }
    public static function reply(array $data): array
    {
        if (!isset($data['shot_plan'])) return ShortDramaVnextSceneShotTest::reply($data);
        $units = array_values(ContentDocument::units($data['document']));
        $window = $data['review_window'] ?? null;
        $quote = static fn($unit) => ['unit' => $unit['ref'], 'start' => 0, 'end' => mb_strlen($unit['text']), 'quote' => $unit['text']];
        $checks = [];
        foreach (array_merge($data['requirements'] ?? [], $data['events'] ?? [], array_map(static fn($id) => ['id' => 'aspect:' . $id], ['identity', 'continuity', 'shot_fidelity'])) as $target) {
            if (!empty($target['episode_ids']) && !in_array($data['document']['episode_id'], $target['episode_ids'], true)) continue;
            $unit = end($units);
            $found = $target['id'] !== 'ending' || $unit['ref'] === 'u6';
            $checks[] = ['id' => $target['id'], 'status' => $found ? 'covered' : ($window ? 'not_observed' : 'missing'),
                'scope' => $window ? 'window' : 'whole_episode', 'reason' => $found ? '该范围内有实际正文证据' : '本范围未观察到结尾事件',
                'evidence' => $found ? [$quote($unit)] : []];
        }
        return ['document_hash' => $data['document']['content_hash'], 'shot_hash' => $data['shot_plan']['content_hash'], 'checks' => $checks, 'narrative_changes' => []];
    }
    public static function receipt(array $data): array
    {
        $reply = self::reply($data);
        return isset($reply['content']) ? $reply : ['content' => json_encode($reply, JSON_UNESCAPED_UNICODE), 'finish_reason' => 'stop'];
    }
    public static function capacity(array $messages): array
    {
        $data = json_decode($messages['content'], true);
        if (isset($data['shot_plan']) && !isset($data['window_reviews'])
            && (!isset($data['review_window']) || count($data['shot_plan']['shots']) > 2)) throw new \RuntimeException('capacity exceeded', 413);
        return ['max_tokens' => 8192];
    }
    private function execution(?array &$saved, ?callable $capacity = null): array
    {
        return ['capacity' => $capacity ?? [self::class, 'capacity'],
            'review_plan' => static function ($document, $shots, $proposal) use (&$saved) { if ($proposal !== null) $saved = $proposal; return $saved; }];
    }
    private function execute(array $context, array $execution, ?callable $provider = null, ?array &$checkpoints = null, ?array &$keys = null): array
    {
        return GenerationPipeline::run($context, static function ($key, $messages) use ($provider, &$keys) {
            $keys[] = $key; $data = json_decode($messages['content'], true); $data = $data['input'] ?? $data;
            return $provider ? $provider($key, $data) : self::receipt($data);
        }, static function () { self::fail('Window review must not start short repair round'); },
            static function ($candidate) use (&$checkpoints) { $checkpoints[] = $candidate; }, $execution);
    }
    public function testLocalAbsenceDoesNotMeanWholeEpisodeMissingAndAllChecksAreRetained(): void
    {
        $input = self::input(); $saved = null; $checkpoints = []; $keys = [];
        $result = $this->execute($input['context'], $this->execution($saved), null, $checkpoints, $keys);
        self::assertSame(['shots', 'review_window_1', 'review_window_2', 'review_window_3', 'review_global'], $keys);
        self::assertSame('ready', $result['coverage_report']['status']);
        self::assertSame('not_observed', $result['coverage_report']['window_review']['windows'][0]['checks'][0]['status']);
        self::assertSame($input['context']['canonical_input'], $result['document']);
        self::assertCount(6, $result['shot_plan']['shots']); self::assertCount(6, $result['shot_plan']['audio_segments']);
        self::assertCount(3, $saved['windows']);
        self::assertCount(3, array_filter($checkpoints, static fn($candidate) => isset($candidate['review_windows'])));
    }
    public function testOldJobsKeepSingleReviewEvenWhenWindowCapabilityIsAvailable(): void
    {
        $context = self::input()['context']; unset($context['scene_review_version']); $saved = null; $keys = []; $checkpoints = [];
        $this->execute($context, $this->execution($saved), null, $checkpoints, $keys);
        self::assertSame(['shots', 'review'], $keys); self::assertNull($saved);
    }
    public function testNewShortJobStillUsesSingleWholeReview(): void
    {
        $saved = null; $keys = []; $checkpoints = [];
        $this->execute(self::input()['context'], $this->execution($saved, static fn() => ['max_tokens' => 8192]), null, $checkpoints, $keys);
        self::assertSame(['shots', 'review'], $keys); self::assertNull($saved);
    }
    public function testWindowAndGlobalEchoMistakesRecoverOncePerStageWithoutRestartingOtherWindows(): void
    {
        $context = self::input()['context'] + ['review_format_version' => 1];
        $saved = null; $keys = []; $checkpoints = [];
        $result = $this->execute($context, $this->execution($saved), static function ($key, $data) {
            $reply = self::reply($data);
            if (in_array($key, ['review_window_2', 'review_global'], true)) $reply['document_hash'] = 'mistyped';
            return isset($reply['content']) ? $reply : ['content' => json_encode($reply, JSON_UNESCAPED_UNICODE), 'finish_reason' => 'stop'];
        }, $checkpoints, $keys);
        self::assertSame(['shots', 'review_window_1', 'review_window_2', 'review_window_2_format', 'review_window_3', 'review_global', 'review_global_format'], $keys);
        self::assertSame('ready', $result['coverage_report']['status']);
        self::assertSame($context['canonical_input'], $result['document']);
        self::assertCount(3, $result['coverage_report']['window_review']['windows']);
    }
    public function testExplicitWholeReviewTruncationFallsBackOnce(): void
    {
        $saved = null; $keys = []; $checkpoints = [];
        $result = $this->execute(self::input()['context'], $this->execution($saved, static fn() => ['max_tokens' => 8192]),
            static fn($key, $data) => $key === 'review' ? ['content' => '{unfinished', 'finish_reason' => 'length'] : self::receipt($data), $checkpoints, $keys);
        self::assertSame(['shots', 'review', 'review_window_1', 'review_window_2', 'review_global'], $keys);
        self::assertSame('ready', $result['coverage_report']['status']); self::assertCount(5, $result['receipts']);
    }
    public function testGenericProviderFailureDoesNotAuthorizeWindowPlan(): void
    {
        $saved = null;
        try { $this->execute(self::input()['context'], $this->execution($saved, static fn() => ['max_tokens' => 8192]), static function ($key, $data) {
            if ($key === 'review') throw new \RuntimeException('request timeout', 413);
            return self::receipt($data);
        }); self::fail('Timeout swallowed'); }
        catch (\RuntimeException $e) { self::assertSame('request timeout', $e->getMessage()); }
        self::assertNull($saved);
    }
    public function testWindowWrongEvidenceGetsOneFormatRepair(): void
    {
        $saved = null; $keys = []; $checkpoints = [];
        $result = $this->execute(self::input()['context'], $this->execution($saved), static function ($key, $data) {
            if ($key !== 'review_window_1') return self::receipt($data);
            $reply = self::reply($data); $reply['checks'][1]['evidence'][0]['quote'] = '伪造引文';
            return ['content' => json_encode($reply, JSON_UNESCAPED_UNICODE)];
        }, $checkpoints, $keys);
        self::assertSame(['shots', 'review_window_1', 'review_window_1_format', 'review_window_2', 'review_window_3', 'review_global'], $keys);
        self::assertSame('ready', $result['coverage_report']['status']);
    }
    public function testGlobalCannotHideWindowContradictionByReturningCovered(): void
    {
        $saved = null; $keys = []; $checkpoints = [];
        try { $this->execute(self::input()['context'], $this->execution($saved), static function ($key, $data) {
            $reply = self::reply($data);
            if ($key === 'review_window_1') $reply['checks'][2]['status'] = 'contradiction';
            return isset($reply['content']) ? $reply : ['content' => json_encode($reply, JSON_UNESCAPED_UNICODE)];
        }, $checkpoints, $keys); self::fail('Window contradiction ignored'); }
        catch (\RuntimeException $e) { self::assertSame(422, $e->getCode()); }
        self::assertSame(['review_global', 'review_global_format'], array_slice($keys, -2));
        self::assertArrayNotHasKey('coverage_report', end($checkpoints));
        self::assertCount(6, end($checkpoints)['shot_plan']['shots']);
    }
    public function testGlobalConflictKeepsDraftAndDoesNotStartUnbudgetedRepair(): void
    {
        $saved = null;
        $result = $this->execute(self::input()['context'], $this->execution($saved), static function ($key, $data) {
            $reply = self::reply($data);
            if ($key === 'review_global') $reply['checks'][2]['status'] = 'contradiction';
            return isset($reply['content']) ? $reply : ['content' => json_encode($reply, JSON_UNESCAPED_UNICODE)];
        });
        self::assertSame('draft', $result['coverage_report']['status']);
        self::assertSame('windowed_content_requires_revision', $result['stop_reason']);
        self::assertCount(6, $result['shot_plan']['audio_segments']);
    }
    public function testWindowFactsMustSurviveGlobalConsolidationAndRemainBeliefs(): void
    {
        $context = self::input()['context']; $context['narrative_contract_version'] = 1; $saved = null; $keys = []; $checkpoints = [];
        $result = $this->execute($context, $this->execution($saved), static function ($key, $data) {
            $reply = self::reply($data);
            if ($key === 'review_window_3') $reply['narrative_changes'][] = ['kind' => 'belief', 'subject_id' => 'char1', 'predicate' => 'knowledge',
                'value' => '记得第六句话', 'observer_id' => 'char1', 'certainty' => 'confirmed', 'evidence' => $reply['checks'][0]['evidence']];
            // Initial global omission must trigger bounded repair, not erase this observation.
            if ($key === 'review_global_format') $reply['narrative_changes'] = $data['window_reviews'][2]['narrative_changes'];
            return isset($reply['content']) ? $reply : ['content' => json_encode($reply, JSON_UNESCAPED_UNICODE)];
        }, $checkpoints, $keys);
        self::assertSame('review_global_format', end($keys));
        $delta = $result['coverage_report']['narrative_ledger']['delta'];
        self::assertCount(1, $delta); self::assertSame('belief', $delta[0]['kind']); self::assertSame('char1', $delta[0]['observer_id']);
        self::assertSame('第6句完整对白😀。', $delta[0]['evidence'][0]['quote']);
    }
    public function testSingleShotWithTooMuchContextStopsBeforeAnyReviewAndRetainsContent(): void
    {
        $saved = null; $keys = []; $checkpoints = [];
        try { $this->execute(self::input()['context'], $this->execution($saved, static function () { throw new \RuntimeException('capacity', 413); }), null, $checkpoints, $keys);
            self::fail('Oversized window accepted'); }
        catch (\RuntimeException $e) { self::assertSame(413, $e->getCode()); }
        self::assertSame(['shots'], $keys); self::assertNull($saved);
        self::assertCount(6, end($checkpoints)['shot_plan']['shots']);
    }
    public function testRealModelCapacityFitsEachWindowAndGlobalRequestWhileWholeReviewCannotFit(): void
    {
        $input = ShortDramaVnextSceneShotTest::input(150); $context = $input['context'];
        $context['scene_shot_version'] = 0; $context['scene_review_version'] = 1;
        $model = ['context_window' => 32768, 'max_output_tokens' => 16384]; $saved = null; $keys = []; $sizes = [];
        $capacity = static function ($messages) use ($model, &$sizes): array {
            $data = json_decode($messages['content'], true);
            $budget = \app\common\service\app\aigc_short_drama\ShortDramaPlanningBudget::stage($messages['system_prompt'] . $messages['content'], $model, 'script', 8192);
            $sizes[] = ['kind' => isset($data['window_reviews']) ? 'global' : (isset($data['review_window']) ? 'window' : 'whole'), 'estimate' => $budget['input_estimate']];
            return $budget;
        };
        // Existing canonical content and shots are fixtures; this does not claim that
        // the same small model generated them in a single completion.
        $document = $context['canonical_input'];
        $reply = json_decode(ShortDramaVnextSceneShotTest::reply(['document' => $document])['content'], true);
        foreach ($reply['shots'] as &$shot) $shot['visual_description'] = str_repeat('人物看向前方。', 10);
        unset($shot);
        $shots = ShotCompiler::compile($document, $reply, 'existing_shots'); $windowed = false; $checkpoints = [];
        $report = SceneReviewExecution::run($context, $document, $shots, static function ($key, $data, $instruction, $validate) use ($capacity, &$keys) {
            $keys[] = $key; $capacity(GenerationPipeline::messages($data, $instruction, 1));
            return $validate(self::reply($data));
        }, static function ($candidate) use (&$checkpoints) { $checkpoints[] = $candidate; }, $this->execution($saved, $capacity), 1, $windowed);
        self::assertNotContains('review', $keys); self::assertSame('review_global', end($keys));
        self::assertGreaterThan(1, count($saved['windows'])); self::assertTrue($windowed); self::assertSame('ready', $report['status']);
        self::assertSame($shots, end($checkpoints)['shot_plan']);
        self::assertSame($document, end($checkpoints)['document']);
        self::assertSame('global', end($sizes)['kind']); self::assertLessThan(32768, end($sizes)['estimate']);
    }
    public function testWindowStillTruncatedStopsWithoutCreatingRecursivePlan(): void
    {
        $saved = null; $keys = []; $checkpoints = [];
        try { $this->execute(self::input()['context'], $this->execution($saved), static fn($key, $data) => $key === 'review_window_2'
            ? ['content' => '{partial', 'finish_reason' => 'length'] : self::receipt($data), $checkpoints, $keys); self::fail('Window truncation ignored'); }
        catch (\app\common\service\app\aigc_short_drama\ShortDramaOutputLimitException $e) { self::assertSame(413, $e->getCode()); }
        self::assertSame(['shots', 'review_window_1', 'review_window_2'], $keys);
        self::assertCount(3, $saved['windows']); self::assertSame(1, end($checkpoints)['review_windows']['completed']);
        self::assertArrayNotHasKey('coverage_report', end($checkpoints));
    }
    public function testGlobalRequestStillTooLargeKeepsAllWindowResultsWithoutAcceptance(): void
    {
        $saved = null; $keys = []; $checkpoints = [];
        try { $this->execute(self::input()['context'], $this->execution($saved), static function ($key, $data) {
            if ($key === 'review_global') throw new \RuntimeException('global capacity exceeded', 413);
            return self::receipt($data);
        }, $checkpoints, $keys); self::fail('Global capacity ignored'); }
        catch (\RuntimeException $e) { self::assertSame(413, $e->getCode()); }
        $last = end($checkpoints);
        self::assertSame(3, $last['review_windows']['completed']);
        self::assertCount(6, $last['shot_plan']['shots']); self::assertCount(6, ContentDocument::units($last['document']));
        self::assertArrayNotHasKey('coverage_report', $last);
    }
    public function testLocalOmittedChecksCannotSilentlyPass(): void
    {
        $saved = null; $keys = []; $checkpoints = [];
        try { $this->execute(self::input()['context'], $this->execution($saved), static function ($key, $data) {
            $reply = self::reply($data);
            if (isset($data['review_window'])) array_pop($reply['checks']);
            return isset($reply['content']) ? $reply : ['content' => json_encode($reply, JSON_UNESCAPED_UNICODE)];
        }, $checkpoints, $keys); self::fail('Missing check accepted'); }
        catch (\RuntimeException $e) { self::assertSame(422, $e->getCode()); }
        self::assertSame(['shots', 'review_window_1', 'review_window_1_format'], $keys);
    }
    public function testWindowInstructionsKeepConfirmedRevisionHistorySemantics(): void
    {
        $context = self::input()['context']; $context['input_history'] = [['text' => '取消先前的场景变更']]; $saved = null;
        GenerationPipeline::run($context, static function ($key, $messages) {
            $data = json_decode($messages['content'], true);
            if (isset($data['review_window'])) {
                self::assertStringContainsString('不重新启用已替换的旧要求', $messages['system_prompt']);
                self::assertSame('取消先前的场景变更', $data['input_history'][0]['text']);
            }
            return self::receipt($data);
        }, static function () {}, static function () {}, $this->execution($saved));
    }
    public function testFrozenReviewPlanRejectsChangedRevisionAndMissingShots(): void
    {
        $context = self::input()['context']; $document = $context['canonical_input'];
        $shots = ShotCompiler::compile($document, json_decode(ShortDramaVnextSceneShotTest::reply(['document' => $document])['content'], true), 's1');
        $plan = SceneReviewExecution::partition($context, $document, $shots, [self::class, 'capacity'], 1);
        SceneReviewExecution::assertPlan($document, $shots, $plan);
        foreach (['document', 'shot', 'omit', 'reverse', 'structure'] as $kind) {
            $bad = $plan;
            if ($kind === 'document') $bad['document_hash'] = 'other';
            elseif ($kind === 'shot') $bad['shot_hash'] = 'other';
            elseif ($kind === 'omit') array_pop($bad['windows']);
            elseif ($kind === 'reverse') $bad['windows'] = array_reverse($bad['windows']);
            else $bad['windows'] = 'invalid';
            unset($bad['plan_hash']); $bad['plan_hash'] = ContentDocument::hash($bad);
            try { SceneReviewExecution::assertPlan($document, $shots, $bad); self::fail('Invalid plan accepted'); }
            catch (\RuntimeException $e) { self::assertSame(409, $e->getCode()); }
        }
    }
}
