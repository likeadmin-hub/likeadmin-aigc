<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\ShortDramaPlanningBudget as Budget;
use app\common\service\app\aigc_short_drama\ShortDramaStructuredResponse as Response;
use app\common\service\app\aigc_short_drama\ShortDramaScriptGeneration as Script;
use app\common\service\app\aigc_short_drama\ShortDramaStoryGeneration as Story;
use app\common\service\power\TextModelCapacity;
use PHPUnit\Framework\TestCase;

class ShortDramaGenerationReliabilityTest extends TestCase
{
    private function plan(): array
    {
        return ['title' => '调查', 'story_outline' => '调查真相', 'type_judgement' => '悬疑', 'core_theme' => '信任',
            'subjects' => [['id' => 's1', 'name' => '甲']], 'locations' => [['id' => 'l1', 'name' => '旧宅']],
            'storyboard' => [['shot_id' => '1', 'visual_description' => '甲进门']],
            'series_bible' => ['audience' => '成人', 'core_hook' => '谜题', 'logline' => '寻找线索', 'relationships' => ['伙伴'], 'world_rules' => ['现实'], 'series_arc' => '发现真相']];
    }

    public function testFourKModelCanGenerateStoryWithoutThreeEpisodeRequirement(): void
    {
        $calls = 0;
        $base = $this->plan(); $base['storyboard'] = [];
        $result = Story::generate(['multi_episode_stage' => 'story', 'episode_count' => 20], ['max_tokens' => 4096],
            static fn() => ['system_prompt' => '', 'content' => '故事'],
            static function ($key, $messages, $budget) use (&$calls, $base) {
                $calls++; self::assertSame(4096, $budget['max_tokens']);
                return ['result' => ['content' => json_encode($base)]];
            });
        self::assertSame(1, $calls); self::assertSame('调查', $result['result']['title']);
    }

    public function testInputOnlyLimitDoesNotBecomeCombinedContextLimit(): void
    {
        $budget = Budget::stage(str_repeat('中', 1000), ['max_input_tokens' => 2048, 'max_tokens' => 4096], 'story');
        self::assertSame(32768, $budget['context']); self::assertSame(4096, $budget['max_tokens']);
        self::assertSame(32768, TextModelCapacity::output(['max_tokens' => 65536], 65536));
        $this->expectException(\RuntimeException::class);
        Budget::stage(str_repeat('中', 2100), ['max_input_tokens' => 2048], 'story');
    }

    public function testCompleteWrappedJsonAndEscapedBracesAreAccepted(): void
    {
        self::assertSame(['title' => 'a"}b'], Response::decode(['content' => "```json\n" . json_encode(['data' => ['title' => 'a"}b']]) . "\n```"]));
    }

    public function testTruncationIsNotAcceptedEvenIfJsonLooksValid(): void
    {
        $this->expectExceptionCode(413);
        Response::decode(['content' => '{"title":"完整"}', 'finish_reason' => 'length']);
    }

    public function testNestedObjectInsideTruncatedOuterDocumentIsNotSalvaged(): void
    {
        $this->expectExceptionCode(422);
        Response::decode(['content' => '{"subjects":[{"id":"s1"}]']);
    }

    public function testOrdinarySingleUsesOneCallAndFormatRepairIsBounded(): void
    {
        foreach ([false, true] as $malformed) {
            $calls = [];
            $result = Script::generate([], ['max_tokens' => 8192], ['system_prompt' => '', 'content' => '写剧本'],
                function ($key) use (&$calls, $malformed) {
                    $calls[] = $key;
                    return ['result' => ['content' => $malformed && count($calls) === 1 ? '不是JSON' : json_encode($this->plan())]];
                });
            self::assertCount($malformed ? 2 : 1, $calls);
            self::assertSame('调查', $result['payload']['title']);
        }
    }

    public function testSmallModelUsesSkeletonAndOrderedDurableChunks(): void
    {
        $calls = [];
        $result = Script::generate([], ['max_tokens' => 4096], ['system_prompt' => '', 'content' => '写剧本'],
            function ($key) use (&$calls) {
                $calls[] = $key;
                if ($key === 'v3_skeleton') {
                    $plan = $this->plan(); $plan['storyboard'] = [];
                    $plan['scene_beats'] = [['scene_ref_id' => 'l1', 'goal' => '调查', 'entry' => '门外', 'exit' => '屋内', 'shot_count' => 5]];
                } else {
                    preg_match('/scene_1_(\d+)_(\d+)/', $key, $matches);
                    $plan = ['storyboard' => []];
                    for ($i = (int)$matches[1]; $i < (int)$matches[1] + (int)$matches[2]; $i++) {
                        $plan['storyboard'][] = ['shot_id' => 's1_' . $i, 'scene_ref_id' => 'l1', 'subject_ref_ids' => ['s1'], 'visual_description' => '调查' . $i, 'recommended_duration_seconds' => 3];
                    }
                }
                return ['result' => ['content' => json_encode($plan)]];
            });
        self::assertSame(['v3_skeleton', 'v3_scene_1_1_4', 'v3_scene_1_5_1'], $calls);
        self::assertCount(5, $result['payload']['storyboard']);
    }

    public function testSafetyRefusalDoesNotRetry(): void
    {
        $calls = 0;
        try {
            Script::generate([], [], ['system_prompt' => '', 'content' => 'test'], static function () use (&$calls) {
                $calls++; return ['result' => ['content' => '', 'finish_reason' => 'content_filter']];
            });
            self::fail('Refusal accepted');
        } catch (\RuntimeException $error) { self::assertSame(403, $error->getCode()); }
        self::assertSame(1, $calls);
    }
}
