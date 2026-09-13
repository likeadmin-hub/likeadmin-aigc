<?php

namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use app\common\service\app\aigc_short_drama\ShortDramaPromptWorkspace;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use think\Container;

/** Exercises the real script-plan assembly/normalization/repair path with a fake provider only. */
class ShortDramaScriptPlanGenerationContractTest extends TestCase
{
    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testAutomaticSingleDurationPreservesProviderShotCount(): void
    {
        require __DIR__ . '/../fixtures/short_drama_script_plan_fake_provider.php';
        $this->silenceLog();
        \app\common\service\power\MarketTextModelRuntimeService::reset();
        $generated = $this->generate(['multi_episode' => false, 'episode_count' => 1,
            'target_duration_seconds' => 0, 'duration_source' => 'auto']);
        self::assertCount(12, $generated['result']['storyboard']);
        self::assertSame(0, $generated['result']['generation_settings']['target_duration_seconds']);
        self::assertSame('auto', $generated['result']['generation_settings']['duration_source']);
        self::assertSame(0, $generated['result']['review_report']['blocking_count']);
        self::assertCount(1, \app\common\service\power\MarketTextModelRuntimeService::$requests);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testSingleAndShortSeriesCompleteThroughTheProviderBoundary(): void
    {
        require __DIR__ . '/../fixtures/short_drama_script_plan_fake_provider.php';
        $this->silenceLog();
        \app\common\service\power\MarketTextModelRuntimeService::reset();

        $raw = \app\common\service\power\MarketTextModelRuntimeService::generate(701, 23, []);
        $normalizer = new ReflectionMethod(AigcShortDramaService::class, 'normalizeGeneratedPlanResult');
        $normalizer->setAccessible(true);
        $enhance = new ReflectionMethod(AigcShortDramaService::class, 'enhancePlanResult');
        $enhance->setAccessible(true);
        $review = new ReflectionMethod(AigcShortDramaService::class, 'reviewPlanResult');
        $review->setAccessible(true);
        $normalized = $enhance->invoke(null, $normalizer->invoke(null, json_decode($raw['content'], true), '模拟测试故事', ['target_duration_seconds' => 6], '模拟测试'));
        $report = $review->invoke(null, $normalized);
        self::assertSame(0, $report['blocking_count'], json_encode($report['issues'], JSON_UNESCAPED_UNICODE));
        \app\common\service\power\MarketTextModelRuntimeService::reset();

        $single = $this->generate(['target_duration_seconds' => 6]);
        self::assertNotEmpty($single['result']['storyboard']);
        self::assertSame(1, count(\app\common\service\power\MarketTextModelRuntimeService::$requests));

        foreach ([2, 3, 9] as $episodeCount) {
            \app\common\service\power\MarketTextModelRuntimeService::reset();
            $generated = $this->generate([
                'multi_episode' => true,
                'episode_count' => $episodeCount,
                'multi_episode_stage' => 'episodes',
            ]);
            self::assertSame($episodeCount, $generated['result']['episode_count']);
            self::assertCount($episodeCount, $generated['result']['episodes']);
            self::assertSame(range(1, $episodeCount), array_column($generated['result']['episodes'], 'episode_number'));
            self::assertCount(1, \app\common\service\power\MarketTextModelRuntimeService::$requests);
        }
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testLongOutlinesThroughTheFiveHundredEpisodeLimitAreBoundedIntoSafeProviderBatches(): void
    {
        require __DIR__ . '/../fixtures/short_drama_script_plan_fake_provider.php';
        $this->silenceLog();
        foreach ([10 => 2, 50 => 5, 100 => 10, 500 => 50] as $episodeCount => $expectedRequests) {
            \app\common\service\power\MarketTextModelRuntimeService::reset();
            $generated = $this->generate([
                'multi_episode' => true,
                'episode_count' => $episodeCount,
                'multi_episode_stage' => 'episodes',
            ]);
            self::assertCount($episodeCount, $generated['result']['episodes']);
            self::assertSame(range(1, $episodeCount), array_column($generated['result']['episodes'], 'episode_number'));
            self::assertCount($expectedRequests, \app\common\service\power\MarketTextModelRuntimeService::$requests);
        }
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testIncompleteOutlineInvokesRepairAndOnlyReturnsAfterEveryEpisodeIsComplete(): void
    {
        require __DIR__ . '/../fixtures/short_drama_script_plan_fake_provider.php';
        $this->silenceLog();
        \app\common\service\power\MarketTextModelRuntimeService::reset();
        \app\common\service\power\MarketTextModelRuntimeService::$returnIncompleteFirstOutline = true;

        $generated = $this->generate([
            'multi_episode' => true,
            'episode_count' => 3,
            'multi_episode_stage' => 'episodes',
        ]);

        self::assertCount(3, $generated['result']['episodes']);
        self::assertSame(0, $generated['result']['review_report']['blocking_count']);
        self::assertCount(2, \app\common\service\power\MarketTextModelRuntimeService::$requests);
        self::assertSame('script_plan_repair', \app\common\service\power\MarketTextModelRuntimeService::$requests[1]['action_code']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testUnrepairableOutlineFailsInsteadOfReturningPartialEpisodes(): void
    {
        require __DIR__ . '/../fixtures/short_drama_script_plan_fake_provider.php';
        $this->silenceLog();
        \app\common\service\power\MarketTextModelRuntimeService::reset();
        \app\common\service\power\MarketTextModelRuntimeService::$returnIncompleteEveryOutline = true;

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('剧本计划质检未通过');
        $this->generate([
            'multi_episode' => true,
            'episode_count' => 3,
            'multi_episode_stage' => 'episodes',
        ]);
    }

    private function generate(array $request): array
    {
        // Supply an in-memory prompt snapshot so the contract test stops at
        // the fake provider rather than reading tenant configuration.
        $request['_prompt_snapshot'] = ShortDramaPromptWorkspace::resolve(701, ['mode' => 'workspace'], []);
        $method = new ReflectionMethod(AigcShortDramaService::class, 'generateScriptPlanResult');
        $method->setAccessible(true);
        return $method->invoke(null, 701, 23, '模拟测试故事', $request, '模拟测试', [
            'id' => 'fake-script-model',
            'model_code' => 'fake-script-model',
        ]);
    }

    private function silenceLog(): void
    {
        Container::getInstance()->instance('log', new class {
            public function write(string $message, string $type = 'log'): void
            {
            }
        });
    }
}
