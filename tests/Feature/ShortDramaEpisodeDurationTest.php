<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\{AigcShortDramaService, ShortDramaCanvasService, ShortDramaEpisodeDuration, ShortDramaTimedScriptGeneration, ShortDramaScriptGeneration, ShortDramaShotDuration, ShortDramaStoryGeneration};
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ShortDramaEpisodeDurationTest extends TestCase
{
    public function testTenantDefaultEpisodeDurationRuleIsEnabled(): void
    {
        self::assertSame([
            'enabled' => true,
            'target_seconds' => 120,
            'min_seconds' => 110,
            'max_seconds' => 130,
        ], ShortDramaEpisodeDuration::defaults());
    }

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

    public function testModelPromptsOmitBlankShotSeedAndAutomaticTarget(): void
    {
        self::assertSame(['min_seconds' => 4, 'max_seconds' => 15], ShortDramaShotDuration::modelRule());
        $instruction = ShortDramaShotDuration::instruction();
        self::assertStringNotContainsString('默认 5 秒', $instruction);
        self::assertStringContainsString('不得沿用空白分镜的初始时长', $instruction);
        $automatic = ShortDramaEpisodeDuration::instruction($this->request());
        self::assertStringNotContainsString('"target_seconds"', $automatic);
        self::assertStringNotContainsString('"min_seconds"', $automatic);
        self::assertStringContainsString('不补时长', $automatic);
        $explicit = ShortDramaEpisodeDuration::instruction($this->request(80));
        self::assertStringContainsString('"target_seconds":80', $explicit);
    }

    public function testCanvasVideoQuoteGuardRejectsModelDurationFallback(): void
    {
        $guard = new ReflectionMethod(ShortDramaCanvasService::class, 'assertWorkflowVideoDuration');
        $guard->setAccessible(true);
        $document = ['nodes_json' => json_encode([['id' => 7, 'type' => 'video',
            'metadata' => ['workflow_planned_duration_seconds' => 8]]], JSON_THROW_ON_ERROR)];
        $guard->invoke(null, $document, '7', 'video', ['duration' => 8], 1, static fn() => 8);
        $guard->invoke(null, $document, '7', 'image', ['duration' => 5], 1);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('当前视频模型不支持分镜要求的8秒');
        $guard->invoke(null, $document, '7', 'video', ['duration' => 8], 1, static fn() => 5);
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

    public function testTimelineKeepsEachEligibleSegmentAsOneShotAndLabelsItsRange(): void
    {
        $request = $this->request(0, [
            ['start_seconds' => 0, 'end_seconds' => 5, 'duration_seconds' => 5],
            ['start_seconds' => 5, 'end_seconds' => 25, 'duration_seconds' => 20],
        ]);
        $skeleton = [
            'title' => '时间码测试', 'story_outline' => '甲在房间寻找钥匙后离开', 'script_lines' => ['甲找到钥匙并离开'],
            'subjects' => [['id' => 'a', 'name' => '甲']], 'locations' => [['id' => 'room', 'name' => '房间']],
            'scene_beats' => [
                ['scene_ref_id' => 'room', 'goal' => '寻找', 'entry' => '进入房间', 'exit' => '找到钥匙', 'key_events' => ['寻找钥匙'], 'duration_seconds' => 5, 'shot_durations' => [2, 3]],
                ['scene_ref_id' => 'room', 'goal' => '离开', 'entry' => '拿到钥匙', 'exit' => '离开房间', 'key_events' => ['走向门口'], 'duration_seconds' => 20, 'shot_durations' => [5, 5, 5, 5]],
            ],
        ];
        $payload = ShortDramaTimedScriptGeneration::generate($request, ['system_prompt' => '创作', 'content' => '故事'],
            static function ($key, $input) use ($skeleton) {
                if (str_contains($key, 'skeleton')) {
                    return $skeleton;
                }
                $context = json_decode(explode("\n以上全局剧情", $input['content'])[0], true);
                return ['storyboard' => array_map(static fn(array $shot): array => [
                    'shot_id' => $shot['shot_id'], 'scene_ref_id' => 'room', 'subject_ref_ids' => ['a'],
                    'visual_description' => '甲完成当前时间码段动作', 'dialogue' => '',
                    'recommended_duration_seconds' => $shot['duration_seconds'],
                ], $context['required_shots'])];
            }, null);

        self::assertEquals([5, 10, 10], array_column($payload['storyboard'], 'recommended_duration_seconds'));
        self::assertSame(['00:00-00:05', '00:05-00:15', '00:15-00:25'], array_column($payload['storyboard'], 'time_range'));
        $this->expectExceptionMessage('分镜未按用户时间码逐段对齐');
        ShortDramaEpisodeDuration::assertPlan(['storyboard' => [
            ['recommended_duration_seconds' => 2], ['recommended_duration_seconds' => 3],
            ['recommended_duration_seconds' => 10], ['recommended_duration_seconds' => 10],
        ]], $request);
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

    public function testEachTimedPartCanUseItsOwnStructuralRepair(): void
    {
        $request = $this->request();
        $calls = [];
        $skeleton = ['title' => '测试', 'story_outline' => '甲找到钥匙后离开房间', 'script_lines' => ['甲找到钥匙后离开房间'],
            'subjects' => [['id' => 'p1', 'name' => '甲']], 'locations' => [['id' => 'l1', 'name' => '房间']],
            'scene_beats' => [['scene_ref_id' => 'l1', 'goal' => '找钥匙', 'entry' => '寻找', 'exit' => '离开', 'key_events' => ['发现钥匙', '离开'],
                'duration_seconds' => 40, 'shot_durations' => [10, 10, 10, 10]]]];
        $payload = ShortDramaTimedScriptGeneration::generate($request, ['system_prompt' => '创作', 'content' => '故事'],
            static function ($key, $input, $budget) use (&$calls, $skeleton) {
                $calls[] = $key;
                if (str_contains($key, 'skeleton')) return $skeleton;
                preg_match('/timed_scene_1_(\d+)/', $key, $matches);
                $start = (int)($matches[1] ?? 1);
                $shots = [];
                for ($n = $start; $n < $start + 2; $n++) $shots[] = ['shot_id' => 's1_' . $n, 'scene_ref_id' => 'l1', 'subject_ref_ids' => ['p1'],
                    'visual_description' => $n < 3 ? '甲继续寻找钥匙' : '甲拿着钥匙走向门口', 'dialogue' => '', 'recommended_duration_seconds' => 10];
                if (!str_contains($key, '_repair')) $shots[0]['recommended_duration_seconds'] = 9;
                return ['storyboard' => $shots];
            }, null, 4096);

        self::assertSame(['timed_skeleton_0', 'timed_scene_1_1', 'timed_scene_1_1_repair', 'timed_scene_1_3', 'timed_scene_1_3_repair'], $calls);
        self::assertSame(2, $payload['timing_diagnostics']['content_repairs']);
        self::assertEquals(40, array_sum(array_column($payload['storyboard'], 'recommended_duration_seconds')));
    }

    public function testTruncatedTimedPartSplitsWithStableIdsAndPreviousShots(): void
    {
        $calls = [];
        $skeleton = ['title' => '钥匙', 'story_outline' => '找到钥匙离开', 'script_lines' => ['找到钥匙离开'],
            'subjects' => [['id' => 'p1', 'name' => '甲']], 'locations' => [['id' => 'l1', 'name' => '房间']],
            'scene_beats' => [['scene_ref_id' => 'l1', 'goal' => '离开', 'entry' => '寻找', 'exit' => '离开',
                'key_events' => ['找钥匙', '开门'], 'duration_seconds' => 20, 'shot_durations' => [10, 10]]]];
        $result = ShortDramaTimedScriptGeneration::generate($this->request(), ['system_prompt' => '创作', 'content' => '故事'],
            static function ($key, $input) use (&$calls, $skeleton) {
                $calls[] = $key;
                if (str_contains($key, 'skeleton')) return $skeleton;
                if ($key === 'timed_scene_1_1') throw new \RuntimeException('输出截断', 413);
                $number = str_contains($key, 'scene_1_2') ? 2 : 1;
                if ($number === 2) self::assertStringContainsString('s1_1', $input['content']);
                return ['storyboard' => [['shot_id' => 's1_' . $number, 'scene_ref_id' => 'l1', 'subject_ref_ids' => ['p1'],
                    'visual_description' => '甲走向门口', 'dialogue' => '', 'recommended_duration_seconds' => 10]]];
            }, null, 4096);
        self::assertSame(['timed_skeleton_0', 'timed_scene_1_1', 'timed_scene_1_1_split_1', 'timed_scene_1_2_split_1'], $calls);
        self::assertSame(['s1_1', 's1_2'], array_column($result['storyboard'], 'shot_id'));
        self::assertEquals(20, $result['timing_diagnostics']['total_seconds']);
    }

    public function testDenseDialogueUsesTargetedFieldRepairInsteadOfRegeneratingTheStoryboardPart(): void
    {
        $request = $this->request(0, [['start_seconds' => 0, 'end_seconds' => 5, 'duration_seconds' => 5]]);
        $calls = [];
        $skeleton = ['title' => '测试', 'story_outline' => '甲和乙在门口争执', 'script_lines' => ['甲和乙在门口争执'],
            'subjects' => [['id' => 'a', 'name' => '甲'], ['id' => 'b', 'name' => '乙']], 'locations' => [['id' => 'door', 'name' => '门口']],
            'scene_beats' => [['scene_ref_id' => 'door', 'goal' => '赶人', 'entry' => '甲守门', 'exit' => '乙离开', 'key_events' => ['甲赶乙离开'],
                'duration_seconds' => 5, 'shot_durations' => [5]]]];
        $payload = ShortDramaTimedScriptGeneration::generate($request, ['system_prompt' => '创作', 'content' => '故事'],
            static function ($key, $input, $budget) use (&$calls, $skeleton) {
                $calls[] = $key;
                if (str_contains($key, 'skeleton')) return $skeleton;
                if (str_contains($key, 'dialogue_repair')) return ['dialogue_repairs' => [['shot_id' => 's1_1', 'dialogue' => '出去，别再烦我。']]];
                return ['storyboard' => [[
                    'shot_id' => 's1_1', 'scene_ref_id' => 'door', 'subject_ref_ids' => ['a', 'b'], 'visual_description' => '甲在门口挥袖赶人，乙后退',
                    'dialogue' => '甲气冲冲地挥袖指着门口对乙怒喊：出去！别再烦我！乙一边后退一边对镜头笑着大声说：开个玩笑，别生气！',
                    'voice_role' => '甲', 'speech_type' => 'character', 'recommended_duration_seconds' => 5,
                ]]];
            }, null);

        self::assertSame(['timed_skeleton_0', 'timed_scene_1_1', 'timed_dialogue_repair_1_1'], $calls);
        self::assertSame('出去，别再烦我。', $payload['storyboard'][0]['dialogue']);
        self::assertSame(1, $payload['timing_diagnostics']['dialogue_repairs']);
        self::assertSame(0, $payload['timing_diagnostics']['content_repairs']);
    }

    public function testCompleteProviderPlanRepairsOnlyItsDenseDialogue(): void
    {
        $request = $this->request(0, [['start_seconds' => 0, 'end_seconds' => 5, 'duration_seconds' => 5]]);
        $calls = [];
        $completePlan = ['title' => '测试', 'story_outline' => '甲赶走乙', 'script_lines' => ['甲赶走乙'],
            'subjects' => [['id' => 'a', 'name' => '甲'], ['id' => 'b', 'name' => '乙']], 'locations' => [['id' => 'door', 'name' => '门口']],
            'storyboard' => [[
                'shot_id' => 's1', 'scene_ref_id' => 'door', 'subject_ref_ids' => ['a', 'b'], 'visual_description' => '甲在门口赶走乙',
                'dialogue' => '甲气冲冲地挥袖指着门口对乙怒喊：出去！别再烦我！乙一边后退一边对镜头笑着大声说：开个玩笑，别生气！',
                'voice_role' => '甲', 'speech_type' => 'character', 'recommended_duration_seconds' => 5,
            ]]];
        $payload = ShortDramaTimedScriptGeneration::generate($request, ['system_prompt' => '创作', 'content' => '故事'],
            static function ($key, $input, $budget) use (&$calls, $completePlan) {
                $calls[] = $key;
                if (str_contains($key, 'dialogue_repair')) return ['dialogue_repairs' => [['shot_id' => 's1', 'dialogue' => '出去，别再烦我。']]];
                return $completePlan;
            }, null);

        self::assertSame(['timed_skeleton_0', 'timed_dialogue_repair_1_1'], $calls);
        self::assertSame('出去，别再烦我。', $payload['storyboard'][0]['dialogue']);
        self::assertSame(1, $payload['timing_diagnostics']['dialogue_repairs']);
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

    public function testTimingUsesTheSameDefaultEpisodeCountAsCreation(): void
    {
        $policy = $this->invoke('episodeDurationSnapshot', '第三集60秒。', ['multi_episode' => true], []);
        self::assertSame([3 => 60.0], $policy['episode_overrides']);
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

    public function testSmallModelSplitsOutputWithoutChangingPlannedTimeOrShotCount(): void
    {
        $sizes = [];
        $plan = ShortDramaTimedScriptGeneration::generate($this->request(), ['system_prompt' => '', 'content' => '甲找到钥匙'],
            static function ($key, $input, $budget) use (&$sizes) {
                if (str_contains($key, 'skeleton')) return ['title' => '钥匙', 'story_outline' => '找到钥匙', 'script_lines' => ['找钥匙'],
                    'subjects' => [['id' => 'a', 'name' => '甲']], 'locations' => [['id' => 'room', 'name' => '家']],
                    'scene_beats' => [['scene_ref_id' => 'room', 'goal' => '找到钥匙', 'entry' => '寻找', 'exit' => '找到',
                        'key_events' => ['找到钥匙'], 'duration_seconds' => 50, 'shot_durations' => [10,10,10,10,10]]]];
                $context = json_decode(explode("\n以上全局剧情", $input['content'])[0], true);
                $sizes[] = count($context['required_shots']);
                return ['storyboard' => array_map(static fn($shot) => ['shot_id' => $shot['shot_id'], 'scene_ref_id' => 'room',
                    'subject_ref_ids' => ['a'], 'visual_description' => '甲寻找钥匙', 'dialogue' => '',
                    'recommended_duration_seconds' => $shot['duration_seconds']], $context['required_shots'])];
            }, null, 4096);
        self::assertSame([2, 2, 1], $sizes);
        self::assertCount(5, $plan['storyboard']);
        self::assertEquals(50, $plan['timing_diagnostics']['total_seconds']);
        self::assertSame(0, $plan['timing_diagnostics']['time_repairs']);
    }

    public function testStorySettingUsesFrozenPolicyInsteadOfLegacyOpenDurationHint(): void
    {
        $request = $this->request() + ['workflow_variant' => 'story_outline_v2', 'multi_episode' => true, 'episode_count' => 2, 'multi_episode_stage' => 'story'];
        $instruction = \app\common\service\app\aigc_short_drama\ShortDramaStoryWorkflow::scopeInstruction($request);
        self::assertStringContainsString('每集时长目标120秒', $instruction);
        self::assertStringContainsString('不补长、不重试', $instruction);
        self::assertStringNotContainsString('否则保持时长开放', $instruction);
    }
}
