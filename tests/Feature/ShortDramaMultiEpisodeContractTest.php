<?php

namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ShortDramaMultiEpisodeContractTest extends TestCase
{
    public function testEpisodeSettingsAreNormalizedForSingleAndMultiEpisodeRequests(): void
    {
        self::assertSame([
            'multi_episode' => false,
            'episode_count' => 1,
        ], $this->invoke('normalizeEpisodeSettings', []));

        self::assertSame([
            'multi_episode' => true,
            'episode_count' => 3,
        ], $this->invoke('normalizeEpisodeSettings', ['multi_episode' => true]));

        self::assertSame([
            'multi_episode' => true,
            'episode_count' => 4,
        ], $this->invoke('normalizeEpisodeSettings', ['episode_count' => 4]));

        self::assertSame(99, $this->invoke('normalizeEpisodeSettings', [
            'multi_episode' => true,
            'episode_count' => 99,
        ])['episode_count']);

        self::assertSame(2, $this->invoke('normalizeEpisodeSettings', [
            'multi_episode' => true,
            'episode_count' => 2,
        ])['episode_count']);

        self::assertSame(10, $this->invoke('normalizeEpisodeSettings', [
            'multi_episode' => true,
            'episode_count' => 10,
        ])['episode_count']);

        self::assertSame(500, $this->invoke('normalizeEpisodeSettings', [
            'multi_episode' => true,
            'episode_count' => 500,
        ])['episode_count']);

        self::assertSame(500, $this->invoke('normalizeEpisodeSettings', [
            'multi_episode' => true,
            'episode_count' => 501,
        ])['episode_count']);
    }

    public function testSingleEpisodeDefaultsToOneMinuteWhenNoDurationIsProvided(): void
    {
        $request = $this->invoke('normalizeCreateRequest', [], []);

        self::assertFalse($request['multi_episode']);
        self::assertSame(1, $request['episode_count']);
        self::assertSame(60, $request['target_duration_seconds']);
        self::assertSame(60, $this->invoke('planningTargetDurationSeconds', '', []));
    }

    public function testNewMultiEpisodeRequestsStartWithStoryStageWhileSingleRequestsStayProductionMode(): void
    {
        $multi = $this->invoke('normalizeCreateRequest', ['multi_episode' => true, 'episode_count' => 4], []);
        self::assertTrue($multi['multi_episode']);
        self::assertSame('story', $multi['multi_episode_stage']);

        $single = $this->invoke('normalizeCreateRequest', [], []);
        self::assertSame('production', $single['multi_episode_stage']);
        self::assertSame('episodes', $this->invoke('nextMultiEpisodeStage', 'story'));
        self::assertSame('production', $this->invoke('nextMultiEpisodeStage', 'episodes'));
    }

    public function testLegacyTaskRequestCanRecoverEpisodeSettingsFromProject(): void
    {
        $request = $this->invoke('hydrateEpisodeSettingsFromProject', [
            'prompt' => '旧任务',
        ], [
            'multi_episode' => 1,
            'episode_count' => 99,
        ]);

        self::assertTrue($request['multi_episode']);
        self::assertSame(99, $request['episode_count']);
    }

    public function testLegacyResultsInferStoryOrOutlineStageWhenStageFieldIsMissing(): void
    {
        self::assertSame('story', $this->invoke('resolveStoredMultiEpisodeStage', [], [
            'multi_episode' => true,
            'episode_count' => 99,
            'story_outline' => '完整故事设定',
            'subjects' => [['id' => 'subject_1', 'name' => '主角']],
            'storyboard' => [],
        ]));
        self::assertSame('episodes', $this->invoke('resolveStoredMultiEpisodeStage', [], [
            'multi_episode' => true,
            'episode_count' => 3,
            'story_outline' => '完整故事设定',
            'subjects' => [['id' => 'subject_1', 'name' => '主角']],
            'episodes' => [
                ['episode_number' => 1, 'title' => '第一集'],
                ['episode_number' => 2, 'title' => '第二集'],
                ['episode_number' => 3, 'title' => '第三集'],
            ],
            'storyboard' => [],
        ]));
    }

    public function testOutlineCannotAdvanceWithoutStableSubjects(): void
    {
        self::assertFalse($this->invoke('planResultHasContent', [
            'multi_episode' => true,
            'episode_count' => 2,
            'multi_episode_stage' => 'episodes',
            'title' => '旧宅谜案',
            'story_outline' => '完整故事设定',
            'episodes' => [
                ['episode_number' => 1],
                ['episode_number' => 2],
            ],
        ]));
    }

    public function testCompactPromptRequiresExactEpisodesAndEpisodeNumberedShots(): void
    {
        $prompt = $this->invoke('buildCompactScriptPlanPrompt', '一个跨越四集的悬疑故事', [
            'multi_episode' => true,
            'episode_count' => 4,
        ], '旧宅谜案');

        self::assertStringContainsString('episodes must contain exactly 4 items', $prompt);
        self::assertStringContainsString('episodes before the last must end with a concrete hook', $prompt);
        self::assertStringContainsString('Every storyboard shot must have episode_number', $prompt);
        self::assertStringContainsString('Every episode must contain at least one scene and at least four concrete shots', $prompt);
        self::assertStringContainsString('episodes[].scenes[].shots[] is the source of truth', $prompt);
        self::assertStringContainsString('"episode_count":4', $prompt);
    }

    public function testCompactPromptSeparatesStoryAndEpisodeOutlineStages(): void
    {
        $story = $this->invoke('buildCompactScriptPlanPrompt', '四集悬疑故事', [
            'multi_episode' => true,
            'episode_count' => 4,
            'multi_episode_stage' => 'story',
        ], '旧宅谜案');
        self::assertStringContainsString('stage 1', $story);
        self::assertStringContainsString('Do not return episodes, scenes, shots, or storyboard', $story);

        $outline = $this->invoke('buildCompactScriptPlanPrompt', '四集悬疑故事', [
            'multi_episode' => true,
            'episode_count' => 4,
            'multi_episode_stage' => 'episodes',
        ], '旧宅谜案');
        self::assertStringContainsString('stage 2', $outline);
        self::assertStringContainsString('exactly 4 outline items', $outline);
        self::assertStringContainsString('storyboard must be an empty array', $outline);
    }

    public function testBatchPromptAndEpisodeReferencesRemainContinuous(): void
    {
        $prompt = $this->invoke('buildCompactScriptPlanPrompt', '悬疑短剧', [
            'multi_episode' => true,
            'episode_count' => 5,
            'episode_batch_start' => 11,
            'episode_batch_end' => 15,
            'episode_batch_context' => '第10集结尾，旧宅地库的灯突然熄灭。',
        ], '旧宅谜案');
        self::assertStringContainsString('full-series episodes 11-15', $prompt);
        self::assertStringContainsString('承接上一批次结尾：第10集结尾，旧宅地库的灯突然熄灭。', $prompt);

        $plan = [
            'episodes' => [[
                'episode_number' => 1,
                'scenes' => [[
                    'scene_id' => 'episode_1_scene_1',
                    'shots' => [[
                        'episode_number' => 1,
                        'scene_ref_id' => 'episode_1_scene_1',
                    ]],
                ]],
                'storyboard' => [[
                    'episode_number' => 1,
                    'scene_ref_id' => 'episode_1_scene_1',
                ]],
            ]],
            'storyboard' => [[
                'episode_number' => 1,
                'scene_ref_id' => 'episode_1_scene_1',
            ]],
        ];
        $offset = $this->invoke('offsetMultiEpisodePlan', $plan, 10);

        self::assertSame(11, $offset['episodes'][0]['episode_number']);
        self::assertSame('episode_11_scene_1', $offset['episodes'][0]['scenes'][0]['scene_id']);
        self::assertSame(11, $offset['episodes'][0]['scenes'][0]['shots'][0]['episode_number']);
        self::assertSame('episode_11_scene_1', $offset['episodes'][0]['storyboard'][0]['scene_ref_id']);
        self::assertSame(11, $offset['storyboard'][0]['episode_number']);
    }

    public function testStructuredBatchContextIsNotLabeledAsOnlyThePreviousHook(): void
    {
        $prompt = $this->invoke('buildCompactScriptPlanPrompt', '长篇悬疑短剧', [
            'multi_episode' => true,
            'episode_count' => 5,
            'episode_total_count' => 20,
            'episode_batch_start' => 6,
            'episode_batch_end' => 10,
            'multi_episode_stage' => 'production',
            'episode_batch_context' => json_encode([
                'series_bible' => ['series_arc' => '全剧主线'],
                'previous_batch_ending_hook' => '旧宅灯灭',
            ], JSON_UNESCAPED_UNICODE),
        ], '旧宅谜案');

        self::assertStringContainsString('previous_batch_ending_hook 是上一批次结尾', $prompt);
        self::assertStringNotContainsString('承接上一批次结尾：{"series_bible"', $prompt);
    }

    public function testMultiEpisodePromptCarriesSeriesBibleAndFullSeriesCount(): void
    {
        $prompt = $this->invoke('buildCompactScriptPlanPrompt', '一部跨越十五集的悬疑短剧', [
            'multi_episode' => true,
            'episode_count' => 5,
            'episode_total_count' => 15,
            'episode_batch_start' => 11,
            'episode_batch_end' => 15,
        ], '旧宅谜案');

        self::assertStringContainsString('The full series has 15 episodes', $prompt);
        self::assertStringContainsString('series_bible', $prompt);
        self::assertStringContainsString('complete Chinese arc for the whole series', $prompt);
        self::assertStringContainsString('stable character/location continuity', $prompt);
    }

    public function testLongEpisodePlansBatchBothOutlineAndProductionStages(): void
    {
        self::assertTrue($this->invoke('shouldBatchMultiEpisodePlan', [
            'multi_episode' => true,
            'episode_count' => 99,
            'multi_episode_stage' => 'episodes',
        ], [
            'multi_episode' => true,
            'episode_count' => 99,
        ]));
        self::assertTrue($this->invoke('shouldBatchMultiEpisodePlan', [
            'multi_episode' => true,
            'episode_count' => 10,
            'multi_episode_stage' => 'production',
        ], [
            'multi_episode' => true,
            'episode_count' => 10,
        ]));
        self::assertFalse($this->invoke('shouldBatchMultiEpisodePlan', [
            'multi_episode' => true,
            'episode_count' => 9,
            'multi_episode_stage' => 'episodes',
        ], [
            'multi_episode' => true,
            'episode_count' => 9,
        ]));
        self::assertFalse($this->invoke('shouldBatchMultiEpisodePlan', [
            'multi_episode' => true,
            'episode_count' => 99,
            'multi_episode_stage' => 'story',
        ], [
            'multi_episode' => true,
            'episode_count' => 99,
        ]));
    }

    public function testSeriesBibleKeepsStableReferencesAndEpisodeDirections(): void
    {
        $result = $this->invoke('normalizeSeriesBible', [
            'series_arc' => '林岚追查旧宅失踪案，最终揭开家族秘密。',
            'theme' => '真相与勇气。',
            'continuity_rules' => ['林岚始终保留录音笔。'],
            'characters' => [['id' => 'subject_1', 'name' => '林岚', 'role' => '调查者', 'arc' => '从怀疑走向揭露']],
            'locations' => [['id' => 'location_1', 'name' => '旧宅', 'purpose' => '主要调查地点']],
            'episode_summaries' => [['episode_number' => 2, 'summary' => '林岚发现暗门。', 'ending_hook' => '门后传来脚步声。']],
        ], '总剧情', [
            ['episode_number' => 1, 'story_outline' => '林岚回到旧宅。', 'ending_hook' => '楼上传来脚步声。'],
            ['episode_number' => 2, 'story_outline' => '林岚发现暗门。', 'ending_hook' => '门后传来脚步声。'],
        ], [
            ['id' => 'subject_1', 'name' => '林岚', 'description' => '调查者'],
        ], [
            ['id' => 'location_1', 'name' => '旧宅', 'description' => '老宅'],
        ], 2);

        self::assertSame('林岚追查旧宅失踪案，最终揭开家族秘密。', $result['series_arc']);
        self::assertSame('林岚', $result['characters'][0]['name']);
        self::assertSame('旧宅', $result['locations'][0]['name']);
        self::assertSame([1, 2], array_column($result['episode_summaries'], 'episode_number'));
    }

    public function testEpisodeBatchContextContainsBibleAndPreviousHook(): void
    {
        $context = $this->invoke('serializeEpisodeBatchContext', [
            'series_arc' => '全剧主线',
            'continuity_rules' => ['人物关系不变'],
        ], '第10集结尾出现暗门。');

        self::assertStringContainsString('series_bible', $context);
        self::assertStringContainsString('全剧主线', $context);
        self::assertStringContainsString('第10集结尾出现暗门', $context);
    }

    public function testMultiEpisodePlansReceiveASeparateOutputBudget(): void
    {
        self::assertSame(3200, $this->invoke('scriptPlanMaxTokens', [
            'multi_episode' => false,
            'episode_count' => 1,
        ]));
        self::assertGreaterThan(4096, $this->invoke('scriptPlanMaxTokens', [
            'multi_episode' => true,
            'episode_count' => 3,
        ]));
        self::assertSame(16000, $this->invoke('scriptPlanMaxTokens', [
            'multi_episode' => true,
            'episode_count' => 10,
        ]));
    }

    public function testGeneratedMultiEpisodePlanKeepsEpisodeStructureOnFlatStoryboard(): void
    {
        $payload = [
            'title' => '旧宅谜案',
            'type_judgement' => '悬疑短剧',
            'core_theme' => '真相需要勇气。',
            'story_outline' => '林岚回到旧宅，逐步发现失踪案背后的真相。',
            'script_lines' => ['林岚回到旧宅。', '线索逐渐出现。', '真相最终揭晓。'],
            'episodes' => [
                ['episode_number' => 1, 'title' => '归来', 'story_outline' => '林岚回到旧宅。', 'script_lines' => ['林岚推开旧宅大门。'], 'ending_hook' => '楼上传来脚步声。'],
                ['episode_number' => 2, 'title' => '暗门', 'story_outline' => '林岚发现暗门。', 'script_lines' => ['墙后的暗门缓缓打开。'], 'ending_hook' => '暗门里出现熟悉照片。'],
                ['episode_number' => 3, 'title' => '真相', 'story_outline' => '林岚揭开真相。', 'script_lines' => ['林岚在晨光中说出真相。'], 'ending_hook' => '主线冲突收束。'],
            ],
            'art_style' => ['base_style' => '电影写实', 'visual_description' => '冷色悬疑光影'],
            'subjects' => [[
                'id' => 'subject_1',
                'name' => '林岚',
                'description' => '调查旧案的年轻记者',
                'category' => 'character',
            ]],
            'locations' => [[
                'id' => 'location_1',
                'story_order' => 1,
                'name' => '旧宅',
                'description' => '布满灰尘的老宅',
            ]],
            'storyboard' => [
                ['shot_id' => '1', 'episode_number' => 1, 'scene_ref_id' => 'location_1', 'subject_ref_ids' => ['subject_1'], 'visual_description' => '林岚推开积灰的大门。'],
                ['shot_id' => '2', 'episode_number' => 2, 'scene_ref_id' => 'location_1', 'subject_ref_ids' => ['subject_1'], 'visual_description' => '林岚移开书柜，露出墙后的暗门。'],
                ['shot_id' => '3', 'episode_number' => 3, 'scene_ref_id' => 'location_1', 'subject_ref_ids' => ['subject_1'], 'visual_description' => '晨光照进客厅，林岚举起关键照片。'],
            ],
        ];

        $result = $this->invoke('normalizeGeneratedPlanResult', $payload, '一个跨越三集的悬疑故事', [
            'multi_episode' => true,
            'episode_count' => 3,
            'model_selections' => [],
        ], '旧宅谜案');

        self::assertTrue($result['multi_episode']);
        self::assertSame(3, $result['episode_count']);
        self::assertCount(3, $result['episodes']);
        self::assertContains('第1集：归来', $result['script_lines']);
        self::assertSame([1, 2, 3], array_values(array_unique(array_column($result['storyboard'], 'episode_number'))));
        foreach ($result['storyboard'] as $shot) {
            self::assertStringStartsWith('第' . $shot['episode_number'] . '集｜', $shot['act']);
        }
    }

    public function testMissingEpisodeNumbersAreDistributedAcrossRequestedEpisodes(): void
    {
        $payload = $this->multiEpisodePayload(4, false, 4);

        $result = $this->invoke('normalizeGeneratedPlanResult', $payload, '一个四集悬疑故事', [
            'multi_episode' => true,
            'episode_count' => 4,
            'model_selections' => [],
        ], '旧宅谜案');

        self::assertCount(4, $result['episodes']);
        self::assertSame([1, 2, 3, 4], array_column($result['episodes'], 'episode_number'));
        self::assertSame(
            [1, 2, 3, 4],
            array_values(array_unique(array_column($result['storyboard'], 'episode_number')))
        );
    }

    public function testInvalidStoredStageFallsBackToResultShapeForLegacyTasks(): void
    {
        self::assertSame('story', $this->invoke('resolveStoredMultiEpisodeStage', [], [
            'multi_episode' => true,
            'episode_count' => 4,
            'multi_episode_stage' => 'broken-stage',
            'subjects' => [['id' => 'subject_1']],
        ]));

        self::assertSame('episodes', $this->invoke('resolveStoredMultiEpisodeStage', [], [
            'multi_episode' => true,
            'episode_count' => 4,
            'stage' => 'broken-stage',
            'episodes' => [['episode_number' => 1]],
        ]));
    }

    public function testMissingEpisodesAreCompletedAtTwoTenAndFiveHundredEpisodeBoundaries(): void
    {
        foreach ([2, 10, 500] as $episodeCount) {
            $payload = $this->multiEpisodePayload(1, false, $episodeCount);
            unset($payload['episodes']);

            $result = $this->invoke('normalizeGeneratedPlanResult', $payload, '多集悬疑故事', [
                'multi_episode' => true,
                'episode_count' => $episodeCount,
                'model_selections' => [],
            ], '旧宅谜案');

            self::assertSame($episodeCount, $result['episode_count']);
            self::assertCount($episodeCount, $result['episodes']);
            self::assertSame(
                range(1, $episodeCount),
                array_values(array_unique(array_column($result['storyboard'], 'episode_number')))
            );
        }
    }

    public function testReviewAndCodeRepairPreserveEpisodeStructure(): void
    {
        $payload = $this->multiEpisodePayload(3, false, 3);
        $result = $this->invoke('normalizeGeneratedPlanResult', $payload, '一个三集悬疑故事', [
            'multi_episode' => true,
            'episode_count' => 3,
            'model_selections' => [],
        ], '旧宅谜案');
        $result['storyboard'][0]['recommended_duration_seconds'] = 99;

        $reviewed = $this->invoke('reviewAndRepairPlanResult', $this->invoke('enhancePlanResult', $result));

        self::assertTrue($reviewed['multi_episode']);
        self::assertSame(3, $reviewed['episode_count']);
        self::assertCount(3, $reviewed['episodes']);
        self::assertSame(
            [1, 2, 3],
            array_values(array_unique(array_column($reviewed['storyboard'], 'episode_number')))
        );
        self::assertSame(5.0, (float)$reviewed['storyboard'][0]['recommended_duration_seconds']);
    }

    public function testStoryStageDoesNotFabricateEpisodesOrStoryboard(): void
    {
        $payload = $this->multiEpisodePayload(0, false, 0);
        unset($payload['episodes'], $payload['storyboard']);
        $result = $this->invoke('normalizeGeneratedPlanResult', $payload, '一个四集悬疑故事', [
            'multi_episode' => true,
            'episode_count' => 4,
            'multi_episode_stage' => 'story',
            'model_selections' => [],
        ], '旧宅谜案');

        self::assertSame('story', $result['multi_episode_stage']);
        self::assertSame([], $result['episodes']);
        self::assertSame([], $result['storyboard']);
        self::assertNotEmpty($result['subjects']);
        $reviewed = $this->invoke('reviewAndRepairPlanResult', $this->invoke('enhancePlanResult', $result), false);
        self::assertSame(0, (int)$reviewed['review_report']['blocking_count']);
    }

    public function testEpisodeOutlineStageRequiresAllRequestedEpisodesAndHasNoShots(): void
    {
        $payload = $this->multiEpisodePayload(4, false, 0);
        unset($payload['storyboard']);
        $result = $this->invoke('normalizeGeneratedPlanResult', $payload, '一个四集悬疑故事', [
            'multi_episode' => true,
            'episode_count' => 4,
            'multi_episode_stage' => 'episodes',
            'model_selections' => [],
        ], '旧宅谜案');

        self::assertSame('episodes', $result['multi_episode_stage']);
        self::assertCount(4, $result['episodes']);
        self::assertSame([], $result['storyboard']);
        self::assertSame([1, 2, 3, 4], array_column($result['episodes'], 'episode_number'));
        $reviewed = $this->invoke('reviewAndRepairPlanResult', $this->invoke('enhancePlanResult', $result), false);
        self::assertSame(0, (int)$reviewed['review_report']['blocking_count']);
    }

    public function testNestedEpisodeScenesAndShotsBecomeLegacyFlatStoryboardItems(): void
    {
        $payload = $this->multiEpisodePayload(2, false, 0);
        $payload['episodes'] = [
            [
                'episode_number' => 1,
                'title' => '归来',
                'story_outline' => '林岚回到旧宅。',
                'script_lines' => ['林岚推门进入旧宅。'],
                'ending_hook' => '楼上传来脚步声。',
                'subjects' => [['subject_ref_id' => 'subject_1', 'name' => '林岚', 'role_in_episode' => '调查旧宅']],
                'scenes' => [[
                    'scene_id' => 'ep1_scene1',
                    'scene_order' => 1,
                    'name' => '旧宅客厅',
                    'description' => '积满灰尘的旧宅客厅',
                    'subject_ref_ids' => ['subject_1'],
                    'shots' => [
                        ['shot_id' => 'ep1_shot1', 'visual_description' => '林岚推开积灰的大门。'],
                        ['shot_id' => 'ep1_shot2', 'visual_description' => '林岚抬头看向楼梯。'],
                    ],
                ]],
            ],
            [
                'episode_number' => 2,
                'title' => '暗门',
                'story_outline' => '林岚发现暗门。',
                'script_lines' => ['林岚移开书柜。'],
                'ending_hook' => '照片揭开旧案真相。',
                'subjects' => [['subject_ref_id' => 'subject_1', 'name' => '林岚', 'role_in_episode' => '发现线索']],
                'scenes' => [[
                    'scene_id' => 'ep2_scene1',
                    'scene_order' => 1,
                    'name' => '旧宅书房',
                    'description' => '堆满旧书的书房',
                    'subject_ref_ids' => ['subject_1'],
                    'shots' => [
                        ['shot_id' => 'ep2_shot1', 'visual_description' => '林岚移开书柜露出暗门。'],
                        ['shot_id' => 'ep2_shot2', 'visual_description' => '林岚举起暗门里的旧照片。'],
                    ],
                ]],
            ],
        ];

        $result = $this->invoke('normalizeGeneratedPlanResult', $payload, '一个两集悬疑故事', [
            'multi_episode' => true,
            'episode_count' => 2,
            'model_selections' => [],
        ], '旧宅谜案');

        self::assertCount(2, $result['episodes']);
        self::assertCount(4, $result['storyboard']);
        self::assertSame([1, 1, 2, 2], array_column($result['storyboard'], 'episode_number'));
        self::assertSame(['ep1_scene1', 'ep1_scene1', 'ep2_scene1', 'ep2_scene1'], array_column($result['storyboard'], 'scene_ref_id'));
        self::assertCount(1, $result['episodes'][0]['scenes']);
        self::assertCount(2, $result['episodes'][0]['scenes'][0]['shots']);
        self::assertSame('ep1_scene1', $result['episodes'][0]['scenes'][0]['scene_id']);
        self::assertNotEmpty($result['subjects']);
        self::assertGreaterThanOrEqual(2, count($result['locations']));
    }

    public function testCompiledComposersExposeEpisodeCountControls(): void
    {
        $root = dirname(__DIR__, 2);
        $home = (string)file_get_contents($root . '/public/_nuxt/index.331744a3.js');
        $plan = (string)file_get_contents($root . '/public/_nuxt/plan.d46b14c5.js');
        $entry = (string)file_get_contents($root . '/public/_nuxt/entry.c46691d5.js');
        $homeCss = (string)file_get_contents($root . '/public/_nuxt/index.a3dd556e20.css');
        $planCss = (string)file_get_contents($root . '/public/_nuxt/plan.95e7815f20.css');

        self::assertStringNotContainsString('多集功能开发中', $home);
        self::assertStringContainsString('episode_count:ta.value?episodeCount.value:1', $home);
        self::assertStringContainsString('episode_count:qt.value?episodeCount.value:1', $plan);
        self::assertStringContainsString('episode_count', $plan);
        self::assertStringContainsString('multi_episode_stage', $plan);
        self::assertStringContainsString(
            'String((e==null?void 0:e.multi_episode_stage)||(e==null?void 0:e.stage)||',
            $plan
        );
        self::assertStringContainsString(
            'i&&!o.length?(Array.isArray(e==null?void 0:e.episodes)&&e.episodes.length?"episodes":"story")',
            $plan
        );
        self::assertStringContainsString(
            'multi_episode_stage:String((e==null?void 0:e.multi_episode_stage)||(e==null?void 0:e.stage)||((!!(e==null?void 0:e.multi_episode)||Number(e==null?void 0:e.episode_count||1)>1)&&!s.length?',
            $plan
        );
        self::assertStringContainsString('分集大纲', $plan);
        self::assertStringContainsString('mode:"advance_stage"', $plan);
        self::assertSame(2, substr_count($home, 's("input",{value:episodeCount.value'));
        self::assertStringContainsString('onInput:episodeCountInput', $home);
        self::assertStringContainsString('episodeCountInput=e=>{episodeCount.value=e.target.value}', $home);
        self::assertStringContainsString('episodeCountCommit=e=>{const a=Number.parseInt', $home);
        self::assertSame(2, substr_count($home, 'onBlur:episodeCountCommit'));
        self::assertStringContainsString('l("input",{value:episodeCount.value', $plan);
        self::assertStringContainsString('onInput:n=>{episodeCount.value=n.target.value},onBlur:n=>{const i=Number.parseInt', $plan);
        self::assertStringNotContainsString('onInput:n=>{const i=Number.parseInt', $plan);
        self::assertSame(2, substr_count(
            $home,
            'ta.value?(r(),i("label",{key:1,class:"drama-episode-count"}'
        ));
        self::assertStringContainsString(
            'qt.value?(c(),u("label",{key:0,class:"plan-composer__episode-count"}',
            $plan
        );
        self::assertStringContainsString('type:"number",min:"2",max:"500"', $home);
        self::assertStringContainsString('type:"number",min:"2",max:"500"', $plan);
        self::assertSame(2, substr_count($entry, './index.331744a3.js?v=20260827-multi-episode-stages'));
        self::assertSame(2, substr_count($entry, './plan.d46b14c5.js?v=20260827-multi-episode-stages'));
        self::assertStringContainsString('./index.a3dd556e20.css', $entry);
        self::assertStringContainsString('./plan.95e7815f20.css', $entry);
        self::assertStringNotContainsString('.css?v=20260817-20', $entry);
        self::assertStringContainsString('drama-episode-count input', $homeCss);
        self::assertStringContainsString('plan-composer__episode-count input', $planCss);
        self::assertStringContainsString('background:#fff;color:#111', $homeCss);
        self::assertStringContainsString('background:#fff;color:#111', $planCss);
    }

    public function testPersistenceSchemaSupportsLargeEpisodePlans(): void
    {
        $root = dirname(__DIR__, 2);
        $install = (string)file_get_contents($root . '/app/apps/aigc_short_drama/migrations/install.sql');
        $upgrade = (string)file_get_contents($root . '/app/apps/aigc_short_drama/migrations/upgrade_20260824_multi_episode_500.sql');

        self::assertStringContainsString('`result_json` longtext', $install);
        self::assertStringContainsString('`plan_json` longtext', $install);
        self::assertStringContainsString('`storyboard_json` longtext', $install);
        self::assertStringContainsString('MODIFY COLUMN `result_json` longtext', $upgrade);
        self::assertStringContainsString('MODIFY COLUMN `plan_json` longtext', $upgrade);
        self::assertStringContainsString('MODIFY COLUMN `storyboard_json` longtext', $upgrade);
    }

    private function invoke(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(AigcShortDramaService::class, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs(null, $arguments);
    }

    private function multiEpisodePayload(int $episodeItems, bool $numberShots, int $shotCount): array
    {
        $episodes = [];
        for ($number = 1; $number <= $episodeItems; $number++) {
            $episodes[] = [
                'episode_number' => $number,
                'title' => '第' . $number . '集',
                'story_outline' => '第' . $number . '集故事推进。',
                'script_lines' => ['第' . $number . '集关键剧情。'],
                'ending_hook' => '第' . $number . '集结尾悬念。',
            ];
        }

        $storyboard = [];
        for ($number = 1; $number <= $shotCount; $number++) {
            $shot = [
                'shot_id' => (string)$number,
                'scene_ref_id' => 'location_1',
                'subject_ref_ids' => ['subject_1'],
                'visual_description' => '第' . $number . '个分镜画面。',
            ];
            if ($numberShots) {
                $shot['episode_number'] = $number;
            }
            $storyboard[] = $shot;
        }

        return [
            'title' => '旧宅谜案',
            'type_judgement' => '悬疑短剧',
            'core_theme' => '揭开真相。',
            'story_outline' => '主角回到旧宅并逐步揭开真相。',
            'script_lines' => ['主角回到旧宅。', '线索逐渐出现。', '真相最终揭晓。'],
            'episodes' => $episodes,
            'art_style' => ['base_style' => '电影写实', 'visual_description' => '悬疑光影'],
            'subjects' => [[
                'id' => 'subject_1',
                'name' => '林岚',
                'description' => '调查旧案的年轻记者',
                'category' => 'character',
            ]],
            'locations' => [[
                'id' => 'location_1',
                'story_order' => 1,
                'name' => '旧宅',
                'description' => '布满灰尘的老宅',
            ]],
            'storyboard' => $storyboard,
        ];
    }
}
