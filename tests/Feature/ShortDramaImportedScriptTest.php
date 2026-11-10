<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\ShortDramaImportedScript;
use PHPUnit\Framework\TestCase;

class ShortDramaImportedScriptTest extends TestCase
{
    public function testImportedEpisodeProductionRemainsSingleThroughRequestNormalization(): void
    {
        $service = \app\common\service\app\aigc_short_drama\AigcShortDramaService::class;
        $normalize = new \ReflectionMethod($service, 'normalizeCreateRequest');
        foreach (['_imported_outline_snapshot' => ['episode_count' => 1], 'imported_script' => true,
            '_canvas_series_planning' => true, '_short_drama_episode_batch' => true] as $marker => $value) {
            $parent = ['multi_episode' => true, 'episode_count' => 1, $marker => $value];
            $child = array_replace($parent, ['multi_episode' => false, 'episode_count' => 1]);
            $request = $normalize->invoke(null, $child, []);
            self::assertFalse($request['multi_episode'], $marker);
            self::assertSame(1, $request['episode_count']);
            self::assertSame('production', $request['multi_episode_stage']);
            self::assertSame('', $request['workflow_variant']);
            self::assertSame('', $request['episode_workflow']);
            self::assertFalse(\app\common\service\app\aigc_short_drama\ShortDramaStoryWorkflow::enabled($request));
        }
    }

    public function testSingleEpisodeApiReceiptSurvivesNormalizationDraftAndOutlineConfirmation(): void
    {
        $raw = ['title'=>'旧信','type_judgement'=>'悬疑','core_theme'=>'寻找真相','story_outline'=>'调查员在旧宅发现旧信并找到故人。',
            'episode_count'=>1,'subjects'=>[['id'=>'s1','name'=>'调查员','description'=>'寻找故人的调查员','category'=>'character']],
            'locations'=>[['id'=>'l1','name'=>'旧宅','description'=>'藏有旧信的房屋']],
            'episodes'=>[['episode_no'=>1,'title'=>'旧信','story_outline'=>'调查员在旧宅找到旧信，循线找到故人。',
                'conflict_point'=>'信中署名与记忆矛盾。','ending_hook'=>'故人交出另一封信。','content'=>'完整的单集原文。',
                'source_range'=>['start_line'=>1,'end_line'=>1]]]];
        $service=\app\common\service\app\aigc_short_drama\AigcShortDramaService::class;
        $plan=(new \ReflectionMethod($service,'fileQaParsePlan'))->invoke(null,$raw,[],'旧信');
        self::assertSame(1,$plan['episode_count']);self::assertCount(1,$plan['episodes']);
        self::assertSame('完整的单集原文。',$plan['episodes'][0]['source_content']);
        $request=['multi_episode'=>true,'episode_count'=>1,'workflow_variant'=>'story_outline_v2','_imported_outline_snapshot'=>$plan];
        self::assertTrue(\app\common\service\app\aigc_short_drama\ShortDramaStoryWorkflow::enabled($request));
        $story=ShortDramaImportedScript::storyDraft($plan);
        $story=\app\common\service\app\aigc_short_drama\ShortDramaStoryDraft::merge($story,['episode_count'=>1],'story');
        $outline=ShortDramaImportedScript::confirmedOutline($plan,$story);
        self::assertCount(1,\app\common\service\app\aigc_short_drama\ShortDramaEpisodeService::validateOutline($outline,1));
        self::assertSame([],\app\common\service\app\aigc_short_drama\ShortDramaStoryWorkflow::issues($outline,'episodes',1));
        $normal=(new \ReflectionMethod($service,'normalizeEpisodeSettings'))->invoke(null,['multi_episode'=>true,'episode_count'=>1]);
        self::assertGreaterThanOrEqual(2,$normal['episode_count'],'Ordinary multi-episode creation retains its existing minimum');
    }

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
        $biography = ['role' => '女主角', 'age' => '青年', 'background' => '曾在旧宅生活的调查员。',
            'motivation' => '寻找失踪的故人。', 'arc' => '从逃避过去到主动揭开真相。'];
        $payload = ['title' => '旧信', 'type_judgement' => ['genre'=>'悬疑'], 'core_theme' => '寻找真相', 'story_outline' => '主角从旧信发现失踪者的踪迹。',
            'subjects' => [['id' => 's1', 'name' => '调查员', 'description' => '寻找失踪者的调查员', 'category' => 'character'] + $biography],
            'locations' => [['id' => 'l1', 'name' => '书房', 'description' => '堆满旧信的书房']], 'episodes' => []];
        for ($i = 1; $i <= 2; $i++) $payload['episodes'][] = ['episode_number' => $i, 'title' => '旧信线索' . $i,
            'story_outline' => $i === 1 ? '调查员寻找旧信，发现新的署名。' : '调查员赶往信中地址，遇见失踪者的妹妹。',
            'conflict_point' => $i === 1 ? '署名与记忆矛盾。' : '妹妹拒绝透露失踪者的去向。', 'ending_hook' => $i === 1 ? '信中还有一个地址。' : '妹妹交出失踪者的日记。',
            'source_content' => $original . $i, 'source_range' => ['start_line' => ($i - 1) * 5001 + 1, 'end_line' => $i * 5001]];
        $method = new \ReflectionMethod(\app\common\service\app\aigc_short_drama\AigcShortDramaService::class, 'fileQaParsePlan');
        $method->setAccessible(true);
        $plan = $method->invoke(null, $payload, [], '旧信');
        self::assertSame('悬疑', $plan['type_judgement']);
        self::assertCount(2, $plan['episodes']);
        foreach ($plan['episodes'] as $episode) {
            self::assertSame($original . $episode['episode_number'], $episode['source_content']);
            self::assertSame(['start_line' => ($episode['episode_number'] - 1) * 5001 + 1, 'end_line' => $episode['episode_number'] * 5001], $episode['source_range']);
        }
        $story = ShortDramaImportedScript::storyDraft($plan);
        foreach ($biography as $field => $value) self::assertSame($value, $story['subjects'][0][$field]);
        $story = \app\common\service\app\aigc_short_drama\ShortDramaStoryDraft::merge($story,
            ['subjects' => [['id' => 's1', 'role' => '用户修改的主角定位']]], 'story');
        self::assertSame('用户修改的主角定位', $story['subjects'][0]['role']);
        self::assertSame([], $story['episodes']);
        self::assertSame('story', $story['multi_episode_stage']);
        self::assertSame([], \app\common\service\app\aigc_short_drama\ShortDramaStoryWorkflow::issues($story, 'story', 2));
        $confirmed = ShortDramaImportedScript::confirmedOutline($plan, $story);
        self::assertSame($story['subjects'], $confirmed['subjects']);
        foreach (array_diff_key($biography, ['role' => true]) as $field => $value) self::assertSame($value, $confirmed['subjects'][0][$field]);
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
    public function testEpisodeScopeCoversLegacyParserSourceWithoutScopeMarker(): void
    {
        $request=['_input_contract_version'=>28,'series_context'=>['current_episode'=>['source_content'=>'甲找到旧信。','source_range'=>['start_line'=>2,'end_line'=>3]]]];
        $instruction=ShortDramaImportedScript::episodeInstruction($request);
        foreach(['story_outline','script_lines','分场','分镜','最后事件','显式修改'] as $field) self::assertStringContainsString($field,$instruction);
        $request['_input_contract_version']=27;
        self::assertSame('',ShortDramaImportedScript::episodeInstruction($request),'Old paid unit input stays frozen');
        self::assertSame('',ShortDramaImportedScript::episodeInstruction(['_input_contract_version'=>28]));
    }
}
