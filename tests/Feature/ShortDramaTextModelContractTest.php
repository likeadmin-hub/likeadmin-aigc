<?php

namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use Exception;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ShortDramaTextModelContractTest extends TestCase
{
    public function testPlanParserAcceptsReasoningAndChoosesTheCompletePlanObject(): void
    {
        $plan = $this->planPayload();
        $content = "<think>prepare the plan</think>\n```json\n"
            . json_encode($plan, JSON_UNESCAPED_UNICODE)
            . "\n```\n{" . '"diagnostic":"done"' . "}";

        $decoded = $this->invoke('decodeLlmJsonObject', $content);

        self::assertSame('City at Night', $decoded['title']);
        self::assertCount(1, $decoded['subjects']);
        self::assertCount(1, $decoded['locations']);
        self::assertCount(1, $decoded['storyboard']);
    }

    public function testPlanParserUnwrapsAProviderContentEnvelope(): void
    {
        $plan = $this->planPayload();
        $content = json_encode([
            'code' => 0,
            'data' => ['content' => json_encode($plan, JSON_UNESCAPED_UNICODE)],
        ], JSON_UNESCAPED_UNICODE);

        $decoded = $this->invoke('decodeLlmJsonObject', $content);

        self::assertSame('location_1', $decoded['storyboard'][0]['scene_ref_id']);
    }

    public function testSelectedTextModelCanBeResolvedBySku(): void
    {
        $matched = $this->invoke('matchModelOption', [[
            'id' => '901',
            'market_sku_id' => 902,
            'model_code' => 'glm-5.2',
        ]], ['market_sku_id' => 902]);

        self::assertSame('901', $matched['id']);
    }

    public function testUnknownExplicitScriptModelDoesNotSilentlyFallback(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('所选剧本策划模型已下架或不可用');

        $this->invoke('resolveSelectedModels', 0, [
            'model_selections' => ['script_plan' => ['id' => 'missing-model']],
        ], [
            'model_groups' => [[
                'key' => 'script_plan',
                'options' => [['id' => '901', 'model_code' => 'glm-5.2']],
            ]],
        ]);
    }

    public function testConfiguredDefaultKeepsEveryTextModelSelectable(): void
    {
        $groups = $this->invoke('applyDefaultTextModel', [[
            'key' => 'script_plan',
            'default' => '901',
            'options' => [
                ['id' => '901', 'model_code' => 'glm-5.2', 'supports_vision' => false],
                ['id' => '902', 'model_code' => 'gpt-5.2', 'supports_vision' => true],
            ],
        ]], ['id' => '902']);

        self::assertSame('902', $groups[0]['default']);
        self::assertCount(2, $groups[0]['options']);
        self::assertFalse($groups[0]['options'][0]['supports_vision']);
    }

    public function testScriptResolutionUsesConfiguredDefaultWhenUserHasNotSelectedOne(): void
    {
        $selected = $this->invoke('resolveSelectedModels', 0, [], [
            'model_groups' => [[
                'key' => 'script_plan',
                'default' => '902',
                'options' => [
                    ['id' => '901', 'model_code' => 'glm-5.2'],
                    ['id' => '902', 'model_code' => 'gpt-5.2'],
                ],
            ]],
        ]);

        self::assertSame('902', $selected['script_plan']['id']);
    }

    public function testExplicitUserTextModelStillOverridesConfiguredDefault(): void
    {
        $selected = $this->invoke('resolveSelectedModels', 0, [
            'model_selections' => ['script_plan' => ['id' => '901']],
        ], [
            'model_groups' => [[
                'key' => 'script_plan',
                'default' => '902',
                'options' => [
                    ['id' => '901', 'model_code' => 'glm-5.2'],
                    ['id' => '902', 'model_code' => 'gpt-5.2'],
                ],
            ]],
        ]);

        self::assertSame('901', $selected['script_plan']['id']);
    }

    public function testAdminSelectorUsesAllTextModelsForTheDefault(): void
    {
        $asset = file_get_contents(dirname(__DIR__, 2) . '/public/admin/assets/config-fge0of94.js');

        self::assertIsString($asset);
        self::assertStringContainsString('default_text_model_id', $asset);
        self::assertStringContainsString('key)==="script_plan"', $asset);
        self::assertStringContainsString('label:"默认文本模型"', $asset);
        self::assertStringContainsString('暂无租户可用的文本模型', $asset);
        self::assertStringNotContainsString('搜索并选择支持视觉的文本模型', $asset);
    }

    private function planPayload(): array
    {
        return [
            'title' => 'City at Night',
            'type_judgement' => 'drama',
            'core_theme' => 'choice',
            'story_outline' => 'A character makes a difficult choice.',
            'script_lines' => ['A character enters the street.'],
            'art_style' => ['base_style' => 'cinematic'],
            'subjects' => [[
                'id' => 'subject_1',
                'name' => 'Lead',
                'description' => 'main character',
                'category' => 'character',
            ]],
            'locations' => [[
                'id' => 'location_1',
                'name' => 'Street',
                'description' => 'night street',
            ]],
            'storyboard' => [[
                'shot_id' => '1',
                'scene_ref_id' => 'location_1',
                'subject_ref_ids' => ['subject_1'],
                'visual_description' => 'Lead walks through the street.',
            ]],
        ];
    }

    private function invoke(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(AigcShortDramaService::class, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs(null, $arguments);
    }
}
