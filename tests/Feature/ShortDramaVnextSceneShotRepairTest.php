<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\vnext\ContentDocument;
use app\common\service\app\aigc_short_drama\vnext\GenerationPipeline;
use app\common\service\app\aigc_short_drama\vnext\SceneShotRepair;
use PHPUnit\Framework\TestCase;

final class ShortDramaVnextSceneShotRepairTest extends TestCase
{
    public static function context(): array
    {
        $context = ShortDramaVnextSceneReviewTest::input()['context'];
        $context['window_shot_repair_version'] = 1; $context['repair_contract_version'] = 2;
        return $context;
    }
    public static function reply(string $key, array $data): array
    {
        if (isset($data['repair_targets'])) {
            $target = $data['repair_targets'][0];
            return ['patches' => [$target + ['visual_description' => '陈默依照正文说出完整对白，画面已校正', 'composition' => '近景', 'camera_movement' => '固定']]];
        }
        $reply = ShortDramaVnextSceneReviewTest::reply($data);
        if ($key === 'review_window_2') {
            $units = ContentDocument::units($data['document']); $unit = $units[$data['review_window']['unit_ids'][0]];
            foreach ($reply['checks'] as &$check) if ($check['id'] === 'aspect:shot_fidelity') {
                $check['status'] = 'contradiction'; $check['reason'] = '当前画面动作与正文不符';
                $check['evidence'] = [['unit' => $unit['ref'], 'start' => 0, 'end' => mb_strlen($unit['text']), 'quote' => $unit['text']]];
            }
            unset($check);
        }
        if (isset($data['window_reviews'])) {
            foreach ($data['window_reviews'] as $review) foreach ($review['checks'] as $local) {
                if ($local['id'] !== 'aspect:shot_fidelity' || $local['status'] === 'covered') continue;
                foreach ($reply['checks'] as &$check) if ($check['id'] === $local['id']) $check = array_merge($local, ['scope' => 'whole_episode']);
                unset($check);
            }
        }
        return $reply;
    }
    public static function receipt(string $key, array $data): array
    {
        $reply = self::reply($key, $data);
        return isset($reply['content']) ? $reply : ['content' => json_encode($reply, JSON_UNESCAPED_UNICODE), 'finish_reason' => 'stop'];
    }
    private function runCase(?callable $provider = null, ?callable $reserve = null, ?array &$checkpoints = null, ?array &$keys = null, ?array $context = null): array
    {
        $saved = null;
        return GenerationPipeline::run($context ?? self::context(), static function ($key, $messages) use ($provider, &$keys) {
            $keys[] = $key; $data = json_decode($messages['content'], true); $data = $data['input'] ?? $data;
            return $provider ? $provider($key, $data) : self::receipt($key, $data);
        }, $reserve ?? static function () {}, static function ($candidate) use (&$checkpoints) { $checkpoints[] = $candidate; },
            ['capacity' => [ShortDramaVnextSceneReviewTest::class, 'capacity'],
                'review_plan' => static function ($document, $shots, $proposal) use (&$saved) { if ($proposal !== null) $saved = $proposal; return $saved; }]);
    }
    public function testOnlyAffectedVisualsAndReviewChangeWhileTextAudioAndOtherShotsStayExact(): void
    {
        $checkpoints = []; $keys = []; $reserved = [];
        $result = $this->runCase(null, static function ($key, $count) use (&$reserved) { $reserved[$key] = $count; }, $checkpoints, $keys);
        self::assertSame(['shots', 'review_window_1', 'review_window_2', 'review_window_3', 'review_global',
            'window_repair_1_patch_2', 'window_repair_1_review_2', 'window_repair_1_global'], $keys);
        self::assertSame(['window_repair_1' => 3], $reserved);
        self::assertSame('ready', $result['coverage_report']['status']); self::assertSame(self::context()['canonical_input'], $result['document']);
        $before = array_values(array_filter($checkpoints, static fn($value) => isset($value['coverage_report'])))[0];
        self::assertSame($before['shot_plan']['audio_segments'], $result['shot_plan']['audio_segments']);
        foreach ($before['shot_plan']['shots'] as $i => $shot) {
            if ($i === 2) { self::assertSame($shot['unit_refs'], $result['shot_plan']['shots'][$i]['unit_refs']); self::assertSame($shot['shot_id'], $result['shot_plan']['shots'][$i]['shot_id']); continue; }
            self::assertSame($shot, $result['shot_plan']['shots'][$i]);
        }
        self::assertNotSame($before['shot_plan']['content_hash'], $result['shot_plan']['content_hash']);
        self::assertSame($before['coverage_report']['window_review']['windows'][0]['checks'], $result['coverage_report']['window_review']['windows'][0]['checks']);
        self::assertSame($before['shot_plan']['content_hash'], $result['coverage_report']['window_review']['windows'][0]['reused_from_shot_hash']);
        self::assertArrayNotHasKey('reused_from_shot_hash', $result['coverage_report']['window_review']['windows'][1]);
    }
    public function testJobsWithoutRepairVersionKeepPreviousStopBehavior(): void
    {
        $context = self::context(); unset($context['window_shot_repair_version']); $keys = []; $checkpoints = [];
        $result = $this->runCase(null, static function () { self::fail('Historical job newly charged for repair'); }, $checkpoints, $keys, $context);
        self::assertSame('windowed_content_requires_revision', $result['stop_reason']); self::assertCount(5, $keys);
    }
    public function testInsufficientRoundBudgetStopsBeforeAnyPatch(): void
    {
        $keys = []; $checkpoints = [];
        $result = $this->runCase(null, static function ($key, $count) { self::assertSame(3, $count); throw new \RuntimeException('budget', 429); }, $checkpoints, $keys);
        self::assertSame('budget_exhausted', $result['stop_reason']); self::assertCount(5, $keys);
        self::assertSame('draft', $result['coverage_report']['status']);
    }
    public function testEmptyPatchStopsWithoutSpendingReviewCalls(): void
    {
        $keys = []; $checkpoints = [];
        $result = $this->runCase(static fn($key, $data) => isset($data['repair_targets']) ? ['content' => '{"patches":[]}'] : self::receipt($key, $data), null, $checkpoints, $keys);
        self::assertSame('unchanged_shots', $result['stop_reason']); self::assertCount(6, $keys);
    }
    public function testPatchCannotTouchReferencesAndGetsOneFormatRepair(): void
    {
        $keys = []; $checkpoints = [];
        $result = $this->runCase(static function ($key, $data) {
            if ($key !== 'window_repair_1_patch_2') return self::receipt($key, $data);
            $reply = self::reply($key, $data); $reply['patches'][0]['refs'] = [['unit' => 'u1']];
            return ['content' => json_encode($reply, JSON_UNESCAPED_UNICODE)];
        }, null, $checkpoints, $keys);
        self::assertSame(['window_repair_1_patch_2', 'window_repair_1_patch_2_format', 'window_repair_1_review_2', 'window_repair_1_global'], array_slice($keys, -4));
        self::assertSame('ready', $result['coverage_report']['status']);
    }
    public function testRepeatedBadPatchCannotModifyAnotherWindowOrPublish(): void
    {
        $keys = []; $checkpoints = [];
        try { $this->runCase(static function ($key, $data) {
            if (!isset($data['repair_targets'])) return self::receipt($key, $data);
            $reply = self::reply($key, $data); $reply['patches'][0]['shot_id'] = 'outside_window';
            return ['content' => json_encode($reply, JSON_UNESCAPED_UNICODE)];
        }, null, $checkpoints, $keys); self::fail('Invalid patch accepted'); }
        catch (\RuntimeException $e) { self::assertSame(422, $e->getCode()); }
        self::assertSame('window_repair_1_patch_2_format', end($keys));
        self::assertSame('draft', end($checkpoints)['coverage_report']['status']);
    }
    public function testNewSemanticDefectCannotReplaceBestDraft(): void
    {
        $keys = []; $checkpoints = [];
        $result = $this->runCase(static function ($key, $data) {
            if ($key !== 'window_repair_1_global') return self::receipt($key, $data);
            $reply = self::reply($key, $data);
            foreach ($reply['checks'] as &$check) if ($check['id'] === 'aspect:continuity') $check['status'] = 'contradiction';
            return ['content' => json_encode($reply, JSON_UNESCAPED_UNICODE)];
        }, null, $checkpoints, $keys);
        self::assertSame('repair_not_improved', $result['stop_reason']); self::assertSame('draft', $result['coverage_report']['status']);
        self::assertSame('aspect:shot_fidelity', $result['coverage_report']['issues'][0]['target_id']);
        self::assertSame($result['shot_plan']['content_hash'], end($checkpoints)['shot_repair']['base_shot_hash']);
        self::assertSame('aspect:continuity', end($checkpoints)['shot_repair']['candidate_report']['issues'][0]['target_id']);
    }
    public function testUnlocalizedGlobalConcernCannotTriggerSpeculativeRepair(): void
    {
        $result = $this->runCase(static function ($key, $data) {
            // All local windows pass; only the global review raises a concern.
            $reply = ShortDramaVnextSceneReviewTest::reply($data);
            if ($key === 'review_global') foreach ($reply['checks'] as &$check) if ($check['id'] === 'aspect:shot_fidelity') $check['status'] = 'contradiction';
            return isset($reply['content']) ? $reply : ['content' => json_encode($reply, JSON_UNESCAPED_UNICODE)];
        }, static function () { self::fail('Unlocalized issue reserved repair'); });
        self::assertSame('windowed_content_requires_revision', $result['stop_reason']);
    }
    public function testStalePatchHashIsRejectedAsRepairableModelError(): void
    {
        $this->expectExceptionCode(422);
        SceneShotRepair::patches([['shot_id' => 's1', 'visual_description' => '保留']], ['patches' => [['shot_id' => 's1', 'before_hash' => 'stale', 'visual_description' => '修改']]]);
    }
    public function testPartialImprovementCanUseSecondBoundedRoundAndRetainsOriginalReuseSource(): void
    {
        $keys = []; $checkpoints = []; $reserved = [];
        $result = $this->runCase(static function ($key, $data) {
            if ($key === 'window_repair_1_patch_2') return ['content' => '{"patches":[]}'];
            if ($key === 'review_window_1' || $key === 'window_repair_1_review_2') return self::receipt('review_window_2', $data);
            return self::receipt($key, $data);
        }, static function ($key, $count) use (&$reserved) { $reserved[$key] = $count; }, $checkpoints, $keys);
        self::assertSame(['window_repair_1' => 5, 'window_repair_2' => 3], $reserved);
        self::assertSame('ready', $result['coverage_report']['status']); self::assertSame(2, $result['coverage_report']['shot_repair']['round']);
        self::assertSame(['window_repair_2_patch_2', 'window_repair_2_review_2', 'window_repair_2_global'], array_slice($keys, -3));
        $first = array_values(array_filter($checkpoints, static fn($value) => isset($value['coverage_report'])))[0];
        self::assertSame($first['shot_plan']['content_hash'], $result['coverage_report']['window_review']['windows'][2]['reused_from_shot_hash']);
        self::assertSame($first['document'], $result['document']); self::assertSame($first['shot_plan']['audio_segments'], $result['shot_plan']['audio_segments']);
    }
    public function testUnchangedSemanticFailureStopsAfterFirstRound(): void
    {
        $keys = []; $checkpoints = []; $reserved = [];
        $result = $this->runCase(static fn($key, $data) => self::receipt($key === 'window_repair_1_review_2' ? 'review_window_2' : $key, $data),
            static function ($key, $count) use (&$reserved) { $reserved[$key] = $count; }, $checkpoints, $keys);
        self::assertSame('repair_not_improved', $result['stop_reason']); self::assertSame(['window_repair_1' => 3], $reserved);
        self::assertSame('window_repair_1_global', end($keys));
    }
    public function testRepairReviewCapacityFailureKeepsBestDraftAndUnacceptedCandidate(): void
    {
        $keys = []; $checkpoints = [];
        try { $this->runCase(static function ($key, $data) {
            if ($key === 'window_repair_1_global') throw new \RuntimeException('capacity exceeded', 413);
            return self::receipt($key, $data);
        }, null, $checkpoints, $keys); self::fail('Unreviewed repair accepted'); }
        catch (\RuntimeException $e) { self::assertSame(413, $e->getCode()); }
        $last = end($checkpoints);
        self::assertSame('draft', $last['coverage_report']['status']);
        self::assertNotSame($last['shot_plan']['content_hash'], $last['shot_repair']['candidate_shot_plan']['content_hash']);
        self::assertSame(self::context()['canonical_input'], $last['document']);
    }
}
