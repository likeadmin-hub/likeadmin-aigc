<?php

namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\ShortDramaStoryWorkflow;
use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use PHPUnit\Framework\TestCase;

class ShortDramaStoryWorkflowTest extends TestCase
{
    public function testNewSubmissionsDefaultOffAndHistoricalTasksKeepReview(): void
    {
        $method = new \ReflectionMethod(AigcShortDramaService::class, 'normalizeCreateRequest');
        $method->setAccessible(true);
        self::assertFalse($method->invoke(null, ['prompt' => '故事'], [])['quality_review_enabled']);
        self::assertTrue($method->invoke(null, ['prompt' => '故事', 'quality_review_enabled' => true], [])['quality_review_enabled']);
        self::assertTrue(ShortDramaStoryWorkflow::qualityReviewEnabled([]));
        self::assertFalse(ShortDramaStoryWorkflow::qualityReviewEnabled(['quality_review_enabled' => false]));
    }

    public function testEpisodeAlwaysInheritsConfirmedOutlineReviewPolicy(): void
    {
        $episode = ['quality_review_enabled' => true, 'episode_number' => 1];
        $disabled = ShortDramaStoryWorkflow::inheritQualityReview($episode, ['quality_review_enabled' => false]);
        self::assertFalse($disabled['quality_review_enabled']);
        self::assertSame(1, $disabled['episode_number']);
        self::assertTrue(ShortDramaStoryWorkflow::inheritQualityReview($disabled, ['quality_review_enabled' => true])['quality_review_enabled']);
        self::assertTrue(ShortDramaStoryWorkflow::inheritQualityReview($disabled, [])['quality_review_enabled']);
    }

    public function testDisabledReviewKeepsStructuralChecksWithoutEditorialStoryGate(): void
    {
        $story = ['title' => '甲', 'type_judgement' => '奇幻', 'core_theme' => '成长',
            'story_outline' => '主角踏上旅程', 'subjects' => [['id' => 's1', 'name' => '甲']],
            'locations' => [['id' => 'l1', 'name' => '山洞']]];
        self::assertSame([], ShortDramaStoryWorkflow::issues($story, 'story', 3, false));
        self::assertNotEmpty(ShortDramaStoryWorkflow::issues($story, 'story', 3, true));
        unset($story['subjects']);
        self::assertContains('subjects', array_column(ShortDramaStoryWorkflow::issues($story, 'story', 3, false), 'path'));
    }
    public function testNewCreationAlwaysSelectsStoryEvenWhenVariantIsMissing(): void
    {
        $method = new \ReflectionMethod(AigcShortDramaService::class, 'normalizeCreateRequest');
        $method->setAccessible(true);
        $base = ['multi_episode' => true, 'episode_count' => 30];
        $legacy = $method->invoke(null, $base, []);
        $new = $method->invoke(null, $base + ['workflow_variant' => ShortDramaStoryWorkflow::VARIANT], []);
        self::assertSame('story', $legacy['multi_episode_stage']);
        self::assertSame(ShortDramaStoryWorkflow::VARIANT, $legacy['workflow_variant']);
        self::assertSame('story', $new['multi_episode_stage']);
        self::assertSame(ShortDramaStoryWorkflow::VARIANT, $new['workflow_variant']);
    }

    public function testLegacyAndSingleRequestsDoNotEnableTheNewWorkflow(): void
    {
        self::assertFalse(ShortDramaStoryWorkflow::enabled(['multi_episode' => true, 'episode_count' => 10]));
        self::assertFalse(ShortDramaStoryWorkflow::enabled(['workflow_variant' => ShortDramaStoryWorkflow::VARIANT, 'episode_count' => 1]));
        self::assertTrue(ShortDramaStoryWorkflow::enabled(['workflow_variant' => ShortDramaStoryWorkflow::VARIANT, 'multi_episode' => true, 'episode_count' => 10]));
    }

    public function testOnlyExplicitConfirmationAdvancesStory(): void
    {
        self::assertSame('story', ShortDramaStoryWorkflow::nextStage('story', false));
        self::assertSame('episodes', ShortDramaStoryWorkflow::nextStage('story', true));
        self::assertSame('episodes', ShortDramaStoryWorkflow::nextStage('episodes', false));
        $this->expectException(\InvalidArgumentException::class);
        ShortDramaStoryWorkflow::nextStage('episodes', true);
    }

    public function testUploadedStoryWorkspaceNeverDispatchesAnotherStoryLlm(): void
    {
        $request = ['workflow_variant' => ShortDramaStoryWorkflow::VARIANT, 'multi_episode' => true,
            'episode_count' => 10, '_generation_version' => 3, 'source' => 'market_file_qa_parse'];
        self::assertTrue(ShortDramaStoryWorkflow::enabled($request));
        self::assertFalse(ShortDramaStoryWorkflow::workerOwned($request));
        $request['source'] = 'revision';
        self::assertTrue(ShortDramaStoryWorkflow::workerOwned($request));
    }

    public function testIncompleteStoryReportsMissingFields(): void
    {
        $issues = ShortDramaStoryWorkflow::issues(['title' => ''], 'story', 10);
        self::assertContains('title', array_column($issues, 'path'));
        self::assertContains('subjects', array_column($issues, 'path'));
        self::assertContains('series_bible.series_arc', array_column($issues, 'path'));
    }

    public function testOneEpisodeBatchStillKnowsItsActualSeriesPosition(): void
    {
        $scope = ShortDramaStoryWorkflow::scopeInstruction(['workflow_variant' => ShortDramaStoryWorkflow::VARIANT,
            'multi_episode_stage' => 'episodes', 'episode_count' => 1, 'episode_total_count' => 100, 'episode_batch_start' => 27]);
        self::assertStringContainsString('全剧总集数=100', $scope);
        self::assertStringContainsString('第 27–27 集', $scope);
        self::assertStringContainsString('全剧最终集是第 100 集', $scope);
        self::assertSame('', ShortDramaStoryWorkflow::scopeInstruction(['episode_count' => 3]));
    }

    public function testStoryScaleUsesCurrentCountAndKeepsDurationUnknownUnlessProvided(): void
    {
        $request = ['workflow_variant' => ShortDramaStoryWorkflow::VARIANT, 'multi_episode' => true,
            'multi_episode_stage' => 'story', 'episode_count' => 300];
        $data = ['episode_count' => 3, 'episode_total_count' => 3, 'episode_batch_context' => ['old'],
            'revision_base_result' => ['episode_count' => 3, 'subjects' => [['id' => 's1', 'name' => '甲']]]];
        $context = ShortDramaStoryWorkflow::storyContext($data, $request);
        self::assertSame(['target_episode_count' => 300, 'target_duration_seconds' => 0], $context['story_scale']);
        self::assertArrayNotHasKey('episode_count', $context['revision_base_result']);
        self::assertArrayNotHasKey('episode_batch_context', $context);
        self::assertSame($data['revision_base_result']['subjects'], $context['revision_base_result']['subjects']);
        self::assertSame(3, $data['episode_count']);
        $instruction = ShortDramaStoryWorkflow::scopeInstruction($request);
        self::assertStringContainsString('未设置单集时长', $instruction);
        self::assertStringNotContainsString('全剧预计总时长=', $instruction);
        self::assertStringContainsString('不按集数线性增加', $instruction);
        self::assertStringContainsString('素材 ID 保持稳定', $instruction);
        self::assertStringContainsString('不预先穷举', $instruction);
    }
}
