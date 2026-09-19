<?php

namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\ShortDramaStoryWorkflow;
use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use PHPUnit\Framework\TestCase;

class ShortDramaStoryWorkflowTest extends TestCase
{
    public function testCreationSelectsStoryOnlyForExplicitNewVariant(): void
    {
        $method = new \ReflectionMethod(AigcShortDramaService::class, 'normalizeCreateRequest');
        $method->setAccessible(true);
        $base = ['multi_episode' => true, 'episode_count' => 30];
        $legacy = $method->invoke(null, $base, []);
        $new = $method->invoke(null, $base + ['workflow_variant' => ShortDramaStoryWorkflow::VARIANT], []);
        self::assertSame('episodes', $legacy['multi_episode_stage']);
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
