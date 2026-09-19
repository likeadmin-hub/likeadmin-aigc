<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\{AigcShortDramaService, ShortDramaEpisodeDuration, ShortDramaTimedScriptGeneration, ShortDramaScriptGeneration, ShortDramaShotDuration, ShortDramaStoryGeneration};
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ShortDramaEpisodeDurationTest extends TestCase
{
    private function request(float $seconds = 0, array $timeline = []): array
    {
        return ['episode_duration_policy' => ShortDramaEpisodeDuration::snapshot([], $seconds, 0, $timeline)];
    }

    private function invoke(string $method, ...$args)
    {
        $reflection = new ReflectionMethod(AigcShortDramaService::class, $method);
        $reflection->setAccessible(true);
        return $reflection->invoke(null, ...$args);
    }

    public function testDefaultShortResultIsAcceptedWithoutPadding(): void
    {
        $request = $this->request();
        foreach ([60, 110, 120, 130] as $seconds) {
            $shots = [['shot_id' => 's1', 'recommended_duration_seconds' => $seconds]];
            ShortDramaEpisodeDuration::assertPlan(['storyboard' => $shots], $request);
            foreach (['balanceStoryboardDuration', 'repairStoryboardCoverage'] as $method) {
                self::assertSame($shots, $this->invoke($method, $shots, [['id' => 'l1']], [], '故事', $request)['storyboard']);
            }
        }
        self::assertSame([], $this->invoke('storyboardTargetRule', '故事', $request));
    }

    public function testExplicitTimingRemainsExact(): void
    {
        foreach ([60, 120, 180] as $seconds) {
            $request = $this->request($seconds);
            ShortDramaEpisodeDuration::assertPlan(['storyboard' => [['recommended_duration_seconds' => $seconds]]], $request);
            self::assertEquals($seconds, ShortDramaEpisodeDuration::policy($request)['min_seconds']);
        }
        $this->expectException(\RuntimeException::class);
        ShortDramaEpisodeDuration::assertPlan(['storyboard' => [['recommended_duration_seconds' => 119]]], $this->request(120));
    }

    public function testDefaultOverrunFails(): void
    {
        $this->expectException(\RuntimeException::class);
        ShortDramaEpisodeDuration::assertPlan(['storyboard' => [['recommended_duration_seconds' => 131]]], $this->request());
    }

    public function testConflictingInputsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        ShortDramaEpisodeDuration::snapshot([], 120, 60, []);
    }

    public function testTimelineShortClipAndBoundariesArePreserved(): void
    {
        $request = $this->request(0, [
            ['start_seconds' => 0, 'end_seconds' => 1.5, 'duration_seconds' => 1.5],
            ['start_seconds' => 1.5, 'end_seconds' => 10, 'duration_seconds' => 8.5],
        ]);
        $rule = ShortDramaShotDuration::rule($request);
        self::assertEquals(1.5, ShortDramaShotDuration::normalize(1.5, $rule));
        ShortDramaEpisodeDuration::assertPlan(['storyboard' => [['recommended_duration_seconds' => 1.5], ['recommended_duration_seconds' => 8.5]]], $request);
        $this->expectException(\RuntimeException::class);
        ShortDramaEpisodeDuration::assertPlan(['storyboard' => [['recommended_duration_seconds' => 5], ['recommended_duration_seconds' => 5]]], $request);
    }

    public function testLocalRevisionDoesNotRebalanceWholeFilm(): void
    {
        $request = $this->request() + ['revision_message' => '修改第一镜台词', 'revision_policy' => ['mode' => 'local_only']];
        ShortDramaEpisodeDuration::assertPlan(['storyboard' => [['recommended_duration_seconds' => 5]]], $request);
        self::assertTrue(ShortDramaEpisodeDuration::localRevision($request));
        self::assertFalse(ShortDramaEpisodeDuration::active([]));
    }

    public function testSeriesAllocationIsNotMultipliedByEpisodeCount(): void
    {
        ShortDramaStoryGeneration::assertDurationAllocation([100, 140], 2, 240);
        $this->expectException(\RuntimeException::class);
        ShortDramaStoryGeneration::assertDurationAllocation([240, 240], 2, 240);
    }

    public function testBudgetFirstEngineAcceptsShortFilmAndRepairsOnlyFailedPart(): void
    {
        $request = $this->request();
        $calls = [];
        $skeleton = ['title' => '测试', 'story_outline' => '甲找到钥匙离开', 'script_lines' => ['甲找到钥匙离开'],
            'subjects' => [['id' => 'p1', 'name' => '甲']], 'locations' => [['id' => 'l1', 'name' => '房间']],
            'scene_beats' => [['scene_ref_id' => 'l1', 'goal' => '找钥匙', 'entry' => '寻找', 'exit' => '离开', 'key_events' => ['发现钥匙', '离开'],
                'duration_seconds' => 20, 'shot_durations' => [10, 10]]]];
        $payload = ShortDramaTimedScriptGeneration::generate($request, ['system_prompt' => '创作', 'content' => '故事'],
            static function ($key, $input, $budget) use (&$calls, $skeleton) {
                $calls[] = $key;
                if (str_contains($key, 'skeleton')) return $skeleton;
                $shots = [];
                foreach ([1, 2] as $n) $shots[] = ['shot_id' => 's1_' . $n, 'scene_ref_id' => 'l1', 'subject_ref_ids' => ['p1'],
                    'visual_description' => $n === 1 ? '甲找到钥匙' : '甲开门离开', 'dialogue' => '', 'recommended_duration_seconds' => 10];
                if (!str_contains($key, 'repair')) $shots[0]['recommended_duration_seconds'] = 9;
                return ['storyboard' => $shots];
            }, null);
        self::assertCount(3, $calls);
        self::assertEquals(20, array_sum(array_column($payload['storyboard'], 'recommended_duration_seconds')));
        self::assertSame(1, $payload['timing_diagnostics']['content_repairs']);
    }

    public function testNewConfigSnapshotAndLegacyPathsAreSeparate(): void
    {
        $policy = $this->invoke('episodeDurationSnapshot', '女孩找猫', [], ['episode_duration_rule' => ['enabled' => true]]);
        self::assertSame('default', $policy['source']);
        self::assertSame(120, $policy['target_seconds']);
        self::assertSame(0, $this->invoke('planningTargetDurationSeconds', '旧任务', []));
        self::assertSame(120.0, $this->invoke('planningTargetDurationSeconds', '新任务', ['episode_duration_policy' => $policy]));
    }

    public function testAliasesDoNotRequirePaidRepairButInvalidReferencesStillFail(): void
    {
        $plan = ShortDramaTimedScriptGeneration::canonicalSkeleton(['title' => '故事', 'story_outline' => '找钥匙',
            'subjects' => [['subject_id' => 'a', 'name' => '甲']], 'locations' => [['location_id' => 'room', 'name' => '家']],
            'scene_beats' => [['scene_ref_id' => 'room', 'goal' => '找钥匙', 'entry' => '寻找', 'exit' => '找到', 'key_events' => ['找到钥匙'], 'duration_seconds' => 10, 'shot_durations' => [10]]]]);
        ShortDramaTimedScriptGeneration::assertSkeleton($plan, $this->request());
        self::assertSame('a', $plan['subjects'][0]['id']);
        $plan['scene_beats'][0]['scene_ref_id'] = 'beat_01';
        $this->expectExceptionMessage('必须引用真实locations.id');
        ShortDramaTimedScriptGeneration::assertSkeleton($plan, $this->request());
    }

    public function testEpisodeOverridesAndSeriesTotalStayDistinct(): void
    {
        $config = ['episode_duration_rule' => ['enabled' => true]];
        $policy = $this->invoke('episodeDurationSnapshot', '第一集60秒，第二集90秒。找回钥匙。', ['multi_episode' => true, 'episode_count' => 2], $config);
        self::assertSame('default', $policy['source']);
        self::assertSame([1 => 60.0, 2 => 90.0], $policy['episode_overrides']);
        $policy = $this->invoke('episodeDurationSnapshot', '整部总时长240秒，每集120秒。', ['multi_episode' => true, 'episode_count' => 2], $config);
        self::assertSame('series', $policy['scope']);
        self::assertEquals(240, $policy['target_seconds']);
        self::assertEquals([1 => 120, 2 => 120], $policy['episode_overrides']);
        $this->expectException(\Exception::class);
        $this->invoke('episodeDurationSnapshot', '整部总时长240秒，每集100秒。', ['multi_episode' => true, 'episode_count' => 2], $config);
    }

    public function testEditableTimelineRetainsShortDuration(): void
    {
        $rule = ShortDramaShotDuration::rule($this->request(0, [['start_seconds' => 0, 'end_seconds' => 1.5, 'duration_seconds' => 1.5]]));
        $saved = $this->invoke('editableShotData', ['recommended_duration_seconds' => 1.5], $rule);
        self::assertSame(1.5, $saved['recommended_duration_seconds']);
        self::assertSame('00:01.5-00:10', $this->invoke('readableShotTimeRange', [], 0, ['start_seconds' => 1.5], 8.5));
    }

    public function testDirectCompleteResponseUsesTheSameTimingGate(): void
    {
        $plan = ['title' => '钥匙', 'story_outline' => '甲找到钥匙', 'script_lines' => ['甲找到钥匙'],
            'subjects' => [['id' => 'a', 'name' => '甲']], 'locations' => [['id' => 'room', 'name' => '家']],
            'storyboard' => [['shot_id' => '1', 'scene_ref_id' => 'room', 'subject_ref_ids' => ['a'], 'visual_description' => '甲拿起钥匙', 'dialogue' => '', 'recommended_duration_seconds' => 10]]];
        ShortDramaTimedScriptGeneration::assertCompletePlan($plan, $this->request());
        self::assertTrue(true);
        $this->expectException(\RuntimeException::class);
        ShortDramaTimedScriptGeneration::assertCompletePlan($plan, $this->request(60));
    }
}
