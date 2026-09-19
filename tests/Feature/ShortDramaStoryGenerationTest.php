<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\ShortDramaStoryGeneration;
use app\common\service\app\aigc_short_drama\ShortDramaPlanningBudget;
use PHPUnit\Framework\TestCase;

class ShortDramaStoryGenerationTest extends TestCase
{
    public function testLongStoryScaleDoesNotGenerateEpisodesOrMultiplyStoryCalls(): void
    {
        foreach ([3, 300, 500] as $count) {
            $request = ['workflow_variant' => 'story_outline_v2', 'multi_episode' => true,
                'multi_episode_stage' => 'story', 'episode_count' => $count];
            $calls = [];
            $result = ShortDramaStoryGeneration::generate($request,
                ['context_window' => 100000, 'max_tokens' => 16384],
                static fn($r) => ['system_prompt' => \app\common\service\app\aigc_short_drama\ShortDramaStoryWorkflow::scopeInstruction($r), 'content' => '旧宅谜案'],
                function ($key, $messages, $budget) use (&$calls, $count) {
                    $calls[] = $key;
                    self::assertSame(1, $budget['count']);
                    self::assertStringContainsString("用户目标集数={$count}集", $messages['system_prompt']);
                    return ['result' => ['content' => json_encode($this->base())]];
                });
            self::assertSame(['story'], $calls);
            self::assertSame($count, $result['result']['episode_count']);
            self::assertSame([], $result['result']['episodes']);
            self::assertSame([], $result['result']['storyboard']);
            self::assertSame($this->base()['subjects'], $result['result']['subjects']);
        }
    }

    public function testTargetedRevisionCannotChangeOtherEpisodesOrConfirmedStory(): void
    {
        $original = $this->runOutline(30, fn($key, $messages) => $this->response($messages))['result']['episodes'];
        foreach ([1, 15, 30] as $target) {
            $calls = [];
            $result = ShortDramaStoryGeneration::generate(['multi_episode_stage' => 'episodes', 'episode_count' => 30,
                'confirmed_story_snapshot' => $this->base(), 'revision_base_result' => ['episodes' => $original],
                'revision_message' => '修改第' . $target . '集', 'revision_target' => ['type' => 'episode', 'id' => $target],
                'revision_policy' => ['mode' => 'local_only']], ['context_window' => 100000, 'max_tokens' => 16384],
                static function ($request) use ($target) {
                    self::assertSame($target, $request['episode_batch_start']);
                    self::assertCount(1, $request['revision_base_result']['episodes']);
                    return ['system_prompt' => '', 'content' => '修改'];
                }, function ($key) use (&$calls) {
                    $calls[] = $key;
                    return ['result' => ['content' => json_encode(['subjects' => [], 'title' => '恶意覆盖', 'episodes' => [[
                        'episode_number' => 1, 'title' => '修改后', 'story_outline' => '独立的新剧情', 'conflict_point' => '新冲突', 'ending_hook' => '新结尾', 'injected' => true,
                    ]]])]];
                });
            self::assertSame(['outline_' . $target . '_1'], $calls);
            self::assertSame($this->base()['subjects'], $result['result']['subjects']);
            foreach ($original as $index => $episode) {
                if ($index !== $target - 1) self::assertSame($episode, $result['result']['episodes'][$index]);
            }
            self::assertSame($target, $result['result']['episodes'][$target - 1]['episode_number']);
            self::assertArrayNotHasKey('injected', $result['result']['episodes'][$target - 1]);
        }
    }

    public function testEpisodeSelectionFailsClosed(): void
    {
        foreach (['修改第3集', '第三集加强冲突'] as $message) {
            self::assertSame('3', \app\common\service\app\aigc_short_drama\ShortDramaRevisionScope::episodeTarget($message, 30)['id']);
        }
        foreach (['修改第31集', '修改第3至5集', '修改第3集和第5集', '修改这一集'] as $message) {
            try { \app\common\service\app\aigc_short_drama\ShortDramaRevisionScope::episodeTarget($message, 30); self::fail('Ambiguous scope accepted'); }
            catch (\Exception $error) { self::assertNotEmpty($error->getMessage()); }
        }
    }

    private function base(): array
    {
        return ['title' => '测试剧', 'type_judgement' => '悬疑', 'core_theme' => '信任', 'story_outline' => '调查真相',
            'subjects' => [['id' => 's1', 'name' => '甲']], 'locations' => [['id' => 'l1', 'name' => '老宅']],
            'series_bible' => ['audience' => '成人', 'core_hook' => '谜题', 'logline' => '寻找线索', 'relationships' => ['伙伴'], 'world_rules' => ['现实'], 'series_arc' => '发现真相']];
    }
    private function runOutline(int $count, callable $provider): array
    {
        return ShortDramaStoryGeneration::generate(['multi_episode_stage' => 'episodes', 'episode_count' => $count, 'confirmed_story_snapshot' => $this->base()],
            ['context_window' => 100000, 'max_tokens' => 16384],
            static fn($request) => ['system_prompt' => '', 'content' => json_encode(['start' => $request['episode_batch_start'], 'count' => $request['episode_count']])], $provider);
    }
    private function response(array $messages): array
    {
        $request = json_decode($messages['content'], true);
        $episodes = [];
        for ($i = 0; $i < $request['count']; $i++) $episodes[] = ['episode_number' => $i + 1, 'title' => '标题', 'story_outline' => '剧情' . ($request['start'] + $i), 'conflict_point' => '冲突', 'ending_hook' => '结尾'];
        return ['result' => ['content' => json_encode(['episodes' => $episodes])]];
    }
    public function testEverySupportedCountIsExhaustiveAndGloballyNumbered(): void
    {
        for ($count = 2; $count <= 500; $count++) {
            $result = $this->runOutline($count, fn($key, $messages) => $this->response($messages));
            self::assertSame(range(1, $count), array_column($result['result']['episodes'], 'episode_number'), 'count=' . $count);
            self::assertSame('s1', $result['result']['subjects'][0]['id']);
        }
    }
    public function testTruncatedSecondBatchSplitsWithoutRegeneratingFirstBatch(): void
    {
        $calls = [];
        $result = $this->runOutline(20, function ($key, $messages) use (&$calls) {
            $calls[] = $key;
            return $key === 'outline_11_10' ? ['result' => ['content' => '{"episodes":[']] : $this->response($messages);
        });
        self::assertSame(['outline_1_10', 'outline_11_10', 'outline_11_5', 'outline_16_5'], $calls);
        self::assertCount(20, $result['result']['episodes']);
    }
    public function testFiniteRepairAtOneEpisodeDoesNotAcceptEmptyContent(): void
    {
        $calls = 0;
        try { $this->runOutline(2, function () use (&$calls) { $calls++; return ['result' => ['content' => '{}']]; }); self::fail('Incomplete accepted'); }
        catch (\RuntimeException $e) { self::assertSame(422, $e->getCode()); }
        self::assertSame(3, $calls); // two-episode request, one-episode request, one repair; stop.
    }
    public function testCapacityUsesConservativeFallbackAndRejectsOversizeInput(): void
    {
        $budget = ShortDramaPlanningBudget::calculate('灵感', [], 10, true);
        self::assertSame('conservative_fallback', $budget['source']);
        self::assertSame(5, $budget['count']);
        self::assertGreaterThanOrEqual(1024, $budget['margin']);
        $this->expectException(\RuntimeException::class);
        ShortDramaPlanningBudget::calculate(str_repeat('长', 30000), [], 1, true);
    }

    public function testMissingContextMetadataKeepsConservativeInputBudget(): void
    {
        $budget = ShortDramaPlanningBudget::calculate(str_repeat('x', 33400), ['max_tokens' => 65536], 3, true);
        self::assertSame('conservative_fallback', $budget['source']);
        self::assertSame(32768, $budget['context']);
        self::assertLessThan(3, $budget['count']);
    }

    public function testInvalidStoryJsonGetsOneRepairWithStageBoundedOutputBudget(): void
    {
        $calls = [];
        $result = ShortDramaStoryGeneration::generate(['multi_episode_stage' => 'story', 'episode_count' => 50],
            ['context_window' => 100000, 'max_tokens' => 16384], static fn() => ['system_prompt' => '', 'content' => '故事设定'],
            function ($key, $messages, $budget) use (&$calls) {
                $calls[] = $key;
                if ($key === 'story') return ['result' => ['content' => '{']];
                self::assertSame(8192, $budget['max_tokens']);
                self::assertLessThan($budget['output_capacity'], $budget['max_tokens']);
                return ['result' => ['content' => json_encode($this->base())]];
            });
        self::assertSame(['story', 'story_repair'], $calls);
        self::assertSame(50, $result['result']['episode_count']);
        self::assertSame([], $result['result']['episodes']);
    }

    public function testRevisionCarriesSavedEpisodesButNeverUnboundedWholeSeries(): void
    {
        $episodes = [];
        for ($n = 1; $n <= 30; $n++) $episodes[] = ['episode_number' => $n, 'title' => '已编辑标题' . $n];
        $ranges = [];
        ShortDramaStoryGeneration::generate(['multi_episode_stage' => 'episodes', 'episode_count' => 30,
            'confirmed_story_snapshot' => $this->base(), 'revision_base_result' => ['episodes' => $episodes],
            'revision_message' => '加强冲突', 'revision_policy' => ['mode' => 'local_only']],
            ['context_window' => 100000, 'max_tokens' => 16384],
            static function ($request) use (&$ranges) {
                $source = $request['revision_base_result']['episodes'];
                self::assertCount(10, $source);
                self::assertSame('local_only', $request['revision_policy']['mode']);
                self::assertSame('已编辑标题' . $request['episode_batch_start'], $source[0]['title']);
                $ranges[] = array_column($source, 'episode_number');
                return ['system_prompt' => '', 'content' => json_encode(['start' => $request['episode_batch_start'], 'count' => $request['episode_count']])];
            }, fn($key, $messages) => $this->response($messages));
        self::assertSame(range(1, 30), array_merge(...$ranges));
    }

    public function testDuplicateAcrossBatchesDoesNotBecomeSuccess(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('完全重复');
        $this->runOutline(11, function ($key, $messages) {
            if (str_starts_with($key, 'outline_11_')) return ['result' => ['content' => json_encode(['episodes' => [[
                'episode_number' => 1, 'title' => '重复', 'story_outline' => '剧情1', 'conflict_point' => '冲突', 'ending_hook' => '结尾'
            ]]])]];
            return $this->response($messages);
        });
    }

    public function testGlobalNumbersAreAcceptedOnlyWhenTheyMatchTheExactBatchRange(): void
    {
        $result = $this->runOutline(30, function ($key, $messages) {
            $response = $this->response($messages);
            $batch = json_decode($response['result']['content'], true);
            $start = json_decode($messages['content'], true)['start'];
            foreach ($batch['episodes'] as &$episode) $episode['episode_number'] += $start - 1;
            return ['result' => ['content' => json_encode($batch)]];
        });
        self::assertSame(range(1, 30), array_column($result['result']['episodes'], 'episode_number'));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('集号不连续');
        ShortDramaStoryGeneration::episodes(['episodes' => array_slice($result['result']['episodes'], 2, 2)], 2, 11);
    }
}
