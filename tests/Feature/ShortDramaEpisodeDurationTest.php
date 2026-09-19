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
}
