<?php

namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ShortDramaPlanNormalizationContractTest extends TestCase
{
    public function testNamedItemsAcceptProviderAliasesAndMissingDescriptions(): void
    {
        $result = $this->invoke('normalizeGeneratedPlanResult', [
            'title' => '别名字段测试',
            'story_outline' => '主角在旧站台发现一封改变命运的信。',
            'script_lines' => ['主角在旧站台发现一封改变命运的信。'],
            'subjects' => [],
            'locations' => [],
            'storyboard' => [],
            'characters' => [['name' => '林岚']],
            'scenes' => [['name' => '旧站台']],
            'shots' => [[
                'shot_id' => '1',
                'scene_ref_id' => '旧站台',
                'subject_ref_ids' => ['subject_1'],
                'visual_description' => '林岚在旧站台拆开信封。',
            ]],
        ], '别名字段测试', [], '别名字段测试');

        self::assertSame('林岚', $result['subjects'][0]['name']);
        self::assertNotSame('', $result['subjects'][0]['description']);
        self::assertSame('旧站台', $result['locations'][0]['name']);
        self::assertNotSame('', $result['locations'][0]['description']);
        self::assertNotEmpty($result['storyboard']);
    }

    public function testCompleteStoryWithoutSceneArrayGetsStableFallbackSceneAndShots(): void
    {
        $result = $this->invoke('normalizeGeneratedPlanResult', [
            'title' => '无场景数组测试',
            'story_outline' => '程序员在深夜修复系统故障，最终找回失联的同伴。',
            'script_lines' => ['程序员在深夜修复系统故障，最终找回失联的同伴。'],
            'subjects' => [['name' => '程序员']],
            'storyboard' => [],
        ], '无场景数组测试', [], '无场景数组测试');

        self::assertCount(1, $result['locations']);
        self::assertSame('故事主场景', $result['locations'][0]['name']);
        self::assertNotEmpty($result['storyboard']);
        self::assertSame($result['locations'][0]['id'], $result['storyboard'][0]['scene_ref_id']);
    }

    public function testTruncatedProviderPayloadRecoversAliasArrays(): void
    {
        $content = <<<'JSON'
{"title":"截断别名测试","story_outline":"林岚在旧站台等到一封关键来信。","script_lines":["林岚在旧站台等到一封关键来信。"],"characters":[{"name":"林岚"}],"scenes":[{"name":"旧站台"}],"shots":[{"visual_description":"林岚拆开信封。","scene_ref_id":"旧站台"}],"unfinished":
JSON
        ;
        $characters = $this->invoke('extractCompleteJsonValue', $content, 'characters');
        $scenes = $this->invoke('extractCompleteJsonValue', $content, 'scenes');
        $shots = $this->invoke('extractCompleteObjectsFromJsonArray', $content, 'shots');

        self::assertSame('林岚', $characters[0]['name']);
        self::assertSame('旧站台', $scenes[0]['name']);
        self::assertSame('林岚拆开信封。', $shots[0]['visual_description']);
    }

    public function testMissingSubjectsUsePromptMentionOrStableFallback(): void
    {
        $result = $this->invoke('normalizeGeneratedPlanResult', [
            'title' => '主体回退测试',
            'story_outline' => '一个人决定在暴雨中回到旧屋。',
            'script_lines' => ['一个人决定在暴雨中回到旧屋。'],
            'locations' => [['name' => '旧屋', 'description' => '暴雨中的旧屋。']],
            'storyboard' => [],
        ], '主体回退测试', ['subject_mentions' => ['林岚']], '主体回退测试');

        self::assertSame('林岚', $result['subjects'][0]['name']);
        self::assertNotEmpty($result['storyboard']);
    }

    public function testIncompleteErrorMessageIsActionable(): void
    {
        $this->expectExceptionMessage('AI 剧本策划结果不完整，请重试');
        $this->invoke('normalizeGeneratedPlanResult', [
            'title' => '',
            'story_outline' => '',
            'script_lines' => [],
        ], '', [], '');
    }

    private function invoke(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(AigcShortDramaService::class, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs(null, $arguments);
    }
}
