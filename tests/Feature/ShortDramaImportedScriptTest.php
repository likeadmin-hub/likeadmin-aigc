<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\ShortDramaImportedScript;
use PHPUnit\Framework\TestCase;

class ShortDramaImportedScriptTest extends TestCase
{
    public function testFrozenImportIsNotDuplicatedIntoPublicOrPromptDiagnostics(): void
    {
        $method = new \ReflectionMethod(\app\common\service\app\aigc_short_drama\AigcShortDramaService::class, 'stripPromptDiagnostics');
        $method->setAccessible(true);
        $input = ['_imported_outline_snapshot' => ['episodes' => [['source_content' => '全剧原文']]],
            'series_context' => ['current_episode' => ['source_content' => '本集完整原文']]];
        self::assertSame(['series_context' => $input['series_context']], $method->invoke(null, $input));
    }

    public function testParserAdapterKeepsFullSourceAndRangeAcrossNormalization(): void
    {
        $original = str_repeat('主角翻开旧信，寻找失踪者的下落。', 5000) . '结尾发现真实署名。';
        $payload = ['title' => '旧信', 'type_judgement' => '悬疑', 'core_theme' => '寻找真相', 'story_outline' => '主角从旧信发现失踪者的踪迹。',
            'subjects' => [['id' => 's1', 'name' => '调查员', 'description' => '寻找失踪者的调查员', 'category' => 'character']],
            'locations' => [['id' => 'l1', 'name' => '书房', 'description' => '堆满旧信的书房']], 'episodes' => []];
        for ($i = 1; $i <= 2; $i++) $payload['episodes'][] = ['episode_number' => $i, 'title' => '旧信线索' . $i,
            'story_outline' => $i === 1 ? '调查员寻找旧信，发现新的署名。' : '调查员赶往信中地址，遇见失踪者的妹妹。',
            'conflict_point' => $i === 1 ? '署名与记忆矛盾。' : '妹妹拒绝透露失踪者的去向。', 'ending_hook' => $i === 1 ? '信中还有一个地址。' : '妹妹交出失踪者的日记。',
            'source_content' => $original, 'source_range' => ['start_line' => 1, 'end_line' => 5001]];
        $method = new \ReflectionMethod(\app\common\service\app\aigc_short_drama\AigcShortDramaService::class, 'fileQaParsePlan');
        $method->setAccessible(true);
        $plan = $method->invoke(null, $payload, [], '旧信');
        self::assertCount(2, $plan['episodes']);
        foreach ($plan['episodes'] as $episode) {
            self::assertSame($original, $episode['source_content']);
            self::assertSame(['start_line' => 1, 'end_line' => 5001], $episode['source_range']);
        }
        $story = ShortDramaImportedScript::storyDraft($plan);
        self::assertSame([], $story['episodes']);
        self::assertSame('story', $story['multi_episode_stage']);
        self::assertSame([], \app\common\service\app\aigc_short_drama\ShortDramaStoryWorkflow::issues($story, 'story', 2));
        $confirmed = ShortDramaImportedScript::confirmedOutline($plan, $story);
        self::assertSame($plan['episodes'], $confirmed['episodes']);
        self::assertSame('episodes', $confirmed['multi_episode_stage']);
        self::assertSame([], \app\common\service\app\aigc_short_drama\ShortDramaStoryWorkflow::issues($confirmed, 'episodes', 2));
    }

    public function testLongSourceAndEndingArePreservedByteForByte(): void
    {
        $source = "\n" . str_repeat('完整台词与动作。', 10000) . "\n最终真相揭晓。\n";
        self::assertSame($source, ShortDramaImportedScript::sourceContent(['content' => $source]));
        self::assertSame($source, ShortDramaImportedScript::sourceContent(['source_content' => $source]));
        self::assertSame($source, ShortDramaImportedScript::sourceContent(['content' => '', 'source_content' => $source]));
    }

    public function testMissingOriginalIsNotFabricatedFromOutline(): void
    {
        self::assertSame('', ShortDramaImportedScript::sourceContent(['story_outline' => '故事梗概']));
    }

    public function testConflictingOriginalsAreNotSilentlyChosen(): void
    {
        $this->expectExceptionCode(422);
        ShortDramaImportedScript::sourceContent(['content' => '原文甲', 'source_content' => '原文乙']);
    }

    public function testStructuredOriginalIsNotConvertedIntoArrayString(): void
    {
        $this->expectExceptionCode(422);
        ShortDramaImportedScript::sourceContent(['content' => ['正文']]);
    }
}
