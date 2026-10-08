<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\{ShortDramaEpisodeDuration as Duration,
    ShortDramaEpisodeTimingRepair as Repair, ShortDramaTimedScriptGeneration as Timed,
    ShortDramaDialogueSplit as Split};
use PHPUnit\Framework\TestCase;

class ShortDramaEpisodeTimingRepairTest extends TestCase
{
    private function request(float $seconds = 60): array
    {
        return ['_input_contract_version' => 8, 'episode_duration_policy' => Duration::snapshot([], $seconds, 0, [])];
    }

    private function shots(): array
    {
        return array_map(static fn($index, $seconds) => [
            'shot_id' => 's1_' . ($index + 1), 'scene_ref_id' => 'room', 'subject_ref_ids' => ['a'],
            'visual_description' => '甲完成当前动作', 'dialogue' => $index === 3 ? str_repeat('字', 66) : '',
            'voice_role' => $index === 3 ? '甲' : '', 'speech_type' => $index === 3 ? 'character' : 'none',
            'recommended_duration_seconds' => $seconds,
        ], range(0, 5), [8, 5, 10, 8, 14, 15]);
    }

    private function reply(array $shots, array $seconds = [8, 5, 10, 12, 10, 15]): array
    {
        return ['timings' => array_map(static fn($shot, $duration) => [
            'shot_id' => $shot['shot_id'], 'duration_seconds' => $duration,
        ], $shots, $seconds)];
    }

    public function testWholeEpisodeCanBorrowTimeFromLaterProviderPartWithoutChangingContent(): void
    {
        $shots = $this->shots(); $calls = []; $checkpoints = [];
        $plan = Timed::generate($this->request(), ['system_prompt' => '创作', 'content' => '五集故事'],
            function($key, $input) use ($shots, &$calls) {
                $calls[] = $key;
                if (str_contains($key, 'skeleton')) return [
                    'title' => '测试', 'story_outline' => '甲解释来历', 'script_lines' => ['甲解释来历'],
                    'subjects' => [['id' => 'a', 'name' => '甲']], 'locations' => [['id' => 'room', 'name' => '房间']],
                    'scene_beats' => [['scene_ref_id' => 'room', 'goal' => '解释', 'entry' => '进入', 'exit' => '被理解',
                        'key_events' => ['甲解释来历'], 'duration_seconds' => 60, 'shot_durations' => [8, 5, 10, 8, 14, 15]]],
                ];
                if (str_starts_with($key, 'episode_timing_')) {
                    self::assertCount(6, json_decode($input['content'], true)['shots']);
                    return $this->reply($shots);
                }
                return ['storyboard' => array_slice($shots, $key === 'timed_scene_1_1' ? 0 : 4, 4)];
            }, static function($type, $data) use (&$checkpoints) { if ($type === 'script_checkpoint') $checkpoints[] = $data; });
        self::assertCount(4, $calls);
        self::assertSame(['timed_skeleton_0', 'timed_scene_1_1', 'timed_scene_1_5'], array_slice($calls, 0, 3));
        self::assertEquals([8, 5, 10, 12, 10, 15], array_column($plan['storyboard'], 'recommended_duration_seconds'));
        self::assertSame(array_column($shots, 'dialogue'), array_column($plan['storyboard'], 'dialogue'));
        self::assertSame($shots, $checkpoints[0]['storyboard']);
        self::assertEquals(60, $plan['timing_diagnostics']['total_seconds']);
        foreach ($shots as $i => $shot) {
            unset($shot['recommended_duration_seconds']);
            $actual = $plan['storyboard'][$i]; unset($actual['recommended_duration_seconds']);
            self::assertSame($shot, $actual);
        }
    }

    public function testGeneratedAndUploadedDialogueBothRemainVerbatim(): void
    {
        foreach ([[], ['series_context' => ['current_episode' => ['source_content' => '必须保留上传原文']]]] as $source) {
            $shots = $this->shots();
            $result = Repair::adapt(['storyboard' => $shots], $this->request() + $source, fn() => $this->reply($shots));
            self::assertSame(array_column($shots, 'dialogue'), array_column($result['storyboard'], 'dialogue'));
            Duration::assertPlan($result, $this->request());
        }
    }

    public function testValidTimingDoesNotMakeAnAdditionalPaidCall(): void
    {
        $shots = $this->shots(); $shots[3]['dialogue'] = '甲：你好。'; $plan = ['storyboard' => $shots];
        self::assertSame($plan, Repair::adapt($plan, $this->request(), static function() { self::fail('Already fits'); }));
    }

    public function testInvalidTimingPatchIsBoundedAndCannotOverwriteStoryOrExtendTotal(): void
    {
        $shots = $this->shots(); $calls = [];
        $result = Repair::adapt(['storyboard' => $shots], $this->request(), function($key) use ($shots, &$calls) {
            $calls[] = $key;
            return count($calls) === 1 ? $this->reply($shots, [8, 5, 10, 12, 14, 15]) : $this->reply($shots);
        });
        self::assertCount(1, $calls);
        self::assertEquals(60, array_sum(array_column($result['storyboard'], 'recommended_duration_seconds')));
        $calls = 0;
        try {
            Repair::adapt(['storyboard' => $shots], $this->request(), function() use ($shots, &$calls) {
                $calls++; $reply = $this->reply($shots); $reply['timings'][3]['dialogue'] = '丢弃原对白'; return $reply;
            });
            self::fail('Content mutation must be rejected');
        } catch (\RuntimeException $e) { self::assertSame(409, $e->getCode()); self::assertSame(2, $calls); }
    }

    public function testReorderedOrMissingIdsAndInvalidNumbersAreRejected(): void
    {
        $shots = $this->shots();
        foreach (['reverse', 'missing', 'invalid'] as $kind) {
            $calls = 0;
            try {
                Repair::adapt(['storyboard' => $shots], $this->request(), function() use ($kind, $shots, &$calls) {
                    $calls++; $reply = $this->reply($shots);
                    if ($kind === 'reverse') $reply['timings'] = array_reverse($reply['timings']);
                    if ($kind === 'missing') array_pop($reply['timings']);
                    if ($kind === 'invalid') $reply['timings'][3]['duration_seconds'] = 'invalid';
                    return $reply;
                });
                self::fail('Invalid allocation');
            } catch (\RuntimeException $e) { self::assertSame(409, $e->getCode()); self::assertSame(2, $calls); }
        }
    }

    public function testLiveModelUndersizedShotsAreRebalancedWithoutAnotherPaidCall(): void
    {
        $shots = $this->shots();
        $calls = 0;
        $result = Repair::adapt(['storyboard' => $shots], $this->request(), function() use ($shots, &$calls) {
            $calls++; return $this->reply($shots, [2, 15, 15, 8, 15, 5]);
        });
        self::assertSame(1, $calls);
        self::assertEquals(60, array_sum(array_column($result['storyboard'], 'recommended_duration_seconds')));
        foreach ($result['storyboard'] as $i => $shot) {
            self::assertSame($shots[$i]['dialogue'], $shot['dialogue']);
            self::assertGreaterThanOrEqual(max(4, Split::requiredSeconds($shot['dialogue'])), $shot['recommended_duration_seconds']);
            self::assertLessThanOrEqual(15, $shot['recommended_duration_seconds']);
        }
    }

    public function testImpossibleTotalPreservesOriginalWithoutPayingForFutileRepair(): void
    {
        $shots = $this->shots();
        foreach ($shots as &$shot) $shot['dialogue'] = str_repeat('字', 78);
        unset($shot);
        $plan = ['storyboard' => $shots];
        try {
            Repair::adapt($plan, $this->request(), static function() { self::fail('Minimum speech already exceeds total'); });
            self::fail('Impossible budget');
        } catch (\RuntimeException $e) { self::assertSame(409, $e->getCode()); self::assertStringContainsString('增加时长', $e->getMessage()); }
        self::assertSame($shots, $plan['storyboard']);
    }

    public function testTimelineAndLocalEditsNeverBorrowTime(): void
    {
        $plan = ['storyboard' => $this->shots()];
        $timeline = $this->request();
        $timeline['episode_duration_policy'] = Duration::snapshot([], 0, 0, [['start_seconds' => 0, 'end_seconds' => 60, 'duration_seconds' => 60]]);
        foreach ([$timeline, $this->request() + ['revision_message' => '修改第一镜']] as $request) {
            self::assertFalse(Duration::canReallocateShots($request));
            self::assertSame($plan, Repair::adapt($plan, $request, static function() { self::fail('Locked boundary'); }));
            try { Split::adapt($plan, $request, static function() { self::fail('Locked conflict'); }, 'test'); self::fail('Must reject'); }
            catch (\RuntimeException $e) { self::assertSame(409, $e->getCode()); }
        }
    }

    public function testReplayKeysAreStableButDifferentForChangedContent(): void
    {
        $keys = []; $shots = $this->shots();
        for ($i = 0; $i < 3; $i++) {
            if ($i === 2) $shots[0]['visual_description'] = '甲跑进屋内';
            Repair::adapt(['storyboard' => $shots], $this->request(), function($key) use ($shots, &$keys) { $keys[] = $key; return $this->reply($shots); });
        }
        self::assertSame($keys[0], $keys[1]); self::assertNotSame($keys[1], $keys[2]);
    }
}
