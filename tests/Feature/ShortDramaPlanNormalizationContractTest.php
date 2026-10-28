<?php

namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ShortDramaPlanNormalizationContractTest extends TestCase
{
    public function testCharacterBiographiesSurvivePlanNormalizationAndEnhancement(): void
    {
        $biography = ['role' => '女主角', 'age' => '青年', 'background' => '离乡多年的调查员。',
            'motivation' => '查明故人失踪的真相。', 'arc' => str_repeat('从逃避过去到直面真相。', 100)];
        $result = $this->invoke('normalizeGeneratedPlanResult', [
            'title' => '旧信', 'story_outline' => '林岚在旧站台发现一封关键来信。',
            'script_lines' => ['林岚在旧站台拆开信封。'],
            'characters' => [['name' => '林岚', 'description' => '身穿深色风衣。'] + $biography],
            'scenes' => [['name' => '旧站台']],
            'shots' => [['shot_id' => '1', 'visual_description' => '林岚拆开信封。']],
        ], '旧信', [], '旧信');
        $result = $this->invoke('enhancePlanResult', $result);
        foreach ($biography as $field => $value) self::assertSame($value, $result['subjects'][0][$field]);
        self::assertSame('身穿深色风衣。', $result['subjects'][0]['description']);
    }

    public function testBiographyFieldsAreOptionalAndDoNotLeakIntoLocations(): void
    {
        $legacy = $this->invoke('normalizeGeneratedNamedItems', [['name' => '林岚']], 'subject');
        self::assertArrayNotHasKey('age', $legacy[0]);
        $locations = $this->invoke('normalizeGeneratedNamedItems', [['name' => '旧站台', 'role' => '相逢之地', 'age' => '百年']], 'location');
        self::assertSame('相逢之地', $locations[0]['description']);
        self::assertArrayNotHasKey('age', $locations[0]);
        self::assertArrayNotHasKey('role', $locations[0]);
    }

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

    public function testProviderShotsWithoutSceneArrayGetStableFallbackScene(): void
    {
        $result = $this->invoke('normalizeGeneratedPlanResult', [
            'title' => '无场景数组测试',
            'story_outline' => '程序员在深夜修复系统故障，最终找回失联的同伴。',
            'script_lines' => ['程序员在深夜修复系统故障，最终找回失联的同伴。'],
            'subjects' => [['name' => '程序员']],
            'storyboard' => [['shot_id' => '1', 'visual_description' => '程序员在深夜敲击键盘修复故障。']],
        ], '无场景数组测试', [], '无场景数组测试');

        self::assertCount(1, $result['locations']);
        self::assertSame('故事主场景', $result['locations'][0]['name']);
        self::assertNotEmpty($result['storyboard']);
        // The review pass repairs references without inventing missing shots.
        $reviewed = $this->invoke('codeRepairPlanResult', $result)[0];
        self::assertSame($result['locations'][0]['id'], $reviewed['storyboard'][0]['scene_ref_id']);
        self::assertSame($result['storyboard'][0]['visual_description'], $reviewed['storyboard'][0]['visual_description']);
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
            'storyboard' => [['shot_id' => '1', 'visual_description' => '林岚冒着暴雨推开旧屋门。']],
        ], '主体回退测试', ['subject_mentions' => ['林岚']], '主体回退测试');

        self::assertSame('林岚', $result['subjects'][0]['name']);
        self::assertNotEmpty($result['storyboard']);
    }

    public function testMissingProviderShotsCannotBeReplacedWithGenericCoverage(): void
    {
        $this->expectExceptionMessage('AI 剧本策划结果不完整，请重试');
        $this->invoke('normalizeGeneratedPlanResult', [
            'title' => '缺少正文', 'story_outline' => '程序员修复故障。',
            'script_lines' => ['程序员修复故障。'],
            'subjects' => [['name' => '程序员']], 'storyboard' => [],
        ], '程序员修复故障。', [], '缺少正文');
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
