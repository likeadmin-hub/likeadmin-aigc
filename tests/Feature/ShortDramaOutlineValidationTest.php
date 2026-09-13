<?php

namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\ShortDramaEpisodeService as Episodes;
use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use PHPUnit\Framework\TestCase;

class ShortDramaOutlineValidationTest extends TestCase
{
    public function testMissingEpisodeBecomesABlockingRepairIssueInsteadOfAPartialSuccess(): void
    {
        $method = new \ReflectionMethod(AigcShortDramaService::class, 'normalizeGeneratedPlanResult');
        $method->setAccessible(true);
        $plan = $method->invoke(null, ['title' => '测试', 'type_judgement' => '悬疑', 'core_theme' => '真相', 'story_outline' => '测试剧情',
            'subjects' => [['name' => '主角']], 'locations' => [['name' => '车站']],
            'episodes' => [['episode_number' => 1, 'title' => '第一集', 'story_outline' => '剧情', 'conflict_point' => '冲突', 'ending_hook' => '钩子']]],
            '测试', ['multi_episode' => true, 'episode_count' => 3, 'multi_episode_stage' => 'episodes', 'episode_workflow' => 'outline_queue'], '测试');
        $review = new \ReflectionMethod(AigcShortDramaService::class, 'reviewPlanResult');
        $review->setAccessible(true);
        $report = $review->invoke(null, $plan);
        self::assertGreaterThan(0, $report['blocking_count']);
        self::assertContains('outline.episodes.count.invalid', array_column($report['issues'], 'code'));
        self::assertContains('outline.episode.missing', array_column($report['issues'], 'code'));
    }

    public function testMissingEpisodeFieldIsIncludedInTheAutomaticRepairRequest(): void
    {
        $plan = [
            'multi_episode' => true,
            'episode_count' => 3,
            'multi_episode_stage' => 'episodes',
            'episodes' => [
                ['episode_number' => 1, 'title' => '第一集', 'story_outline' => '开场', 'conflict_point' => '冲突', 'ending_hook' => '钩子'],
                ['episode_number' => 2, 'title' => '第二集', 'story_outline' => '发展', 'conflict_point' => '矛盾', 'ending_hook' => '悬念'],
                ['episode_number' => 3, 'title' => '第三集', 'story_outline' => '收束', 'conflict_point' => '', 'ending_hook' => '结局'],
            ],
            'review_report' => [
                'issues' => [[
                    'code' => 'outline.episode.conflict_point.empty',
                    'severity' => 'blocking',
                ]],
            ],
        ];
        $method = new \ReflectionMethod(AigcShortDramaService::class, 'assembleRepairPromptRequest');
        $method->setAccessible(true);
        $request = $method->invoke(null, $plan, '测试灵感');

        self::assertStringContainsString('每集都必须有 title、story_outline、conflict_point、ending_hook', $request['content']);
        self::assertStringContainsString('不得复制另一集代替缺失集', $request['content']);
    }

    public function testPersistedLegacyOutlineWithFallbackEpisodesCannotRemainSuccessful(): void
    {
        $episodes = [];
        for ($number = 1; $number <= 10; $number++) {
            $episodes[] = [
                'episode_number' => $number,
                'title' => '第' . $number . '集',
                'story_outline' => '第' . $number . '集剧情',
                // This mirrors the historical display fallback: it made the
                // card look populated, but did not supply a usable conflict.
                'conflict_point' => $number <= 5 ? '第' . $number . '集冲突' : '',
                'ending_hook' => '第' . $number . '集悬念',
            ];
        }
        $legacy = [
            'multi_episode' => true,
            'episode_count' => 10,
            'multi_episode_stage' => 'episodes',
            'title' => '测试',
            'type_judgement' => '悬疑',
            'core_theme' => '真相',
            'story_outline' => '测试剧情',
            'subjects' => [['name' => '主角']],
            'locations' => [['name' => '旧宅']],
            'episodes' => $episodes,
            'outline_validation_issues' => [[
                'code' => 'outline.episode.missing',
                'path' => 'episodes.5',
                'message' => '缺少第6集大纲',
            ]],
            'review_report' => ['status' => 'passed', 'blocking_count' => 0],
        ];

        $issues = new \ReflectionMethod(AigcShortDramaService::class, 'storedMultiEpisodeOutlineIssues');
        $issues->setAccessible(true);
        $content = new \ReflectionMethod(AigcShortDramaService::class, 'planResultHasContent');
        $content->setAccessible(true);

        self::assertContains('outline.episode.missing', array_column($issues->invoke(null, $legacy), 'code'));
        self::assertContains('outline.episode.conflict_point.empty', array_column($issues->invoke(null, $legacy), 'code'));
        self::assertFalse($content->invoke(null, $legacy));
    }

    public function testMissingShotsAreNotClonedIntoOtherEpisodes(): void
    {
        $method = new \ReflectionMethod(AigcShortDramaService::class, 'applyStoryboardEpisodeStructure');
        $method->setAccessible(true);
        $result = $method->invoke(null, [['shot_id' => 'one', 'episode_number' => 1, 'visual_description' => '主角走进房间']], [], 3);
        self::assertCount(1, $result);
        self::assertSame([1], array_column($result, 'episode_number'));
    }

    public function testFailedCompletedEpisodeRevisionAlsoPausesFollowingWork(): void
    {
        $rows = [['id' => 3, 'episode_number' => 3, 'status' => 'pending', 'completed_once' => 0],
            ['id' => 1, 'episode_number' => 1, 'status' => 'failed', 'completed_once' => 1],
            ['id' => 2, 'episode_number' => 2, 'status' => 'success', 'completed_once' => 1]];
        self::assertSame(1, Episodes::nextInitialEpisode($rows)['id']);
    }
}
