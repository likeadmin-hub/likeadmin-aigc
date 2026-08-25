<?php

namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use Exception;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ShortDramaScriptPromptConfigContractTest extends TestCase
{
    public function testScriptPromptTemplateRendersAllRuntimePlaceholders(): void
    {
        $rendered = $this->invoke(
            'renderScriptPlanPromptTemplate',
            "Business rule\n{{default_prompt}}\nIdea={{user_prompt}}\nTitle={{title}}\nRequest={{request_json}}",
            'DEFAULT CONTRACT',
            'A lost-key mystery',
            ['episode_count' => 3, 'multi_episode' => true],
            'The Missing Key'
        );

        self::assertStringContainsString('Business rule', $rendered);
        self::assertStringContainsString('DEFAULT CONTRACT', $rendered);
        self::assertStringContainsString('Idea=A lost-key mystery', $rendered);
        self::assertStringContainsString('Title=The Missing Key', $rendered);
        self::assertStringContainsString('"episode_count":3', $rendered);
        self::assertStringNotContainsString('{{default_prompt}}', $rendered);
    }

    public function testPromptTemplateSupportsFullOverrideAndEmptyFallback(): void
    {
        self::assertSame('Custom prompt only', $this->invoke(
            'renderScriptPlanPromptTemplate',
            'Custom prompt only',
            'DEFAULT CONTRACT',
            'User idea',
            [],
            'Title'
        ));

        self::assertSame('{{default_prompt}}', $this->invoke(
            'normalizeScriptPromptConfigValue',
            " \r\n ",
            '{{default_prompt}}'
        ));
    }

    public function testPlanningTemplatesRetainTheDefaultStructureContract(): void
    {
        self::assertStringContainsString('{{default_prompt}}', $this->invoke(
            'ensurePlanningPromptTemplateContract',
            '自定义多集规则',
            '{{default_prompt}}'
        ));
        self::assertSame('{{default_prompt}}', $this->invoke(
            'ensurePlanningPromptTemplateContract',
            '{{default_prompt}}',
            '{{default_prompt}}'
        ));
    }

    public function testPromptConfigurationRejectsOversizedValues(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('60000');

        $this->invoke(
            'validateScriptPromptConfigValue',
            str_repeat('x', 60001),
            'fallback',
            'Script prompt'
        );
    }

    public function testSingleAndMultiEpisodePromptDefaultsAreDistinct(): void
    {
        $defaults = $this->invoke('scriptPromptDefaults');

        self::assertArrayHasKey('system_prompt', $defaults);
        self::assertArrayHasKey('prompt_template', $defaults);
        self::assertArrayHasKey('multi_episode_system_prompt', $defaults);
        self::assertArrayHasKey('multi_episode_prompt_template', $defaults);
        self::assertNotSame($defaults['system_prompt'], $defaults['multi_episode_system_prompt']);
        self::assertNotSame($defaults['prompt_template'], $defaults['multi_episode_prompt_template']);
        self::assertStringContainsString('exactly that many numbered episodes', $defaults['multi_episode_system_prompt']);
        self::assertStringContainsString('{{default_prompt}}', $defaults['multi_episode_prompt_template']);
    }

    public function testAdminBundleLoadsSavesAndResetsPromptConfiguration(): void
    {
        $root = dirname(__DIR__, 2);
        $bundle = (string)file_get_contents($root . '/public/admin/assets/prompt-Df7pQ2mN.js');
        $basicConfigBundle = (string)file_get_contents($root . '/public/admin/assets/config-fge0of94.js');
        $service = (string)file_get_contents(
            $root . '/app/common/service/app/aigc_short_drama/AigcShortDramaService.php'
        );

        foreach ([
            'script_system_prompt',
            'script_prompt_template',
            'multi_episode_script_system_prompt',
            'multi_episode_script_prompt_template',
        ] as $field) {
            self::assertGreaterThanOrEqual(1, substr_count($bundle, $field));
            self::assertStringContainsString("array_key_exists('{$field}', \$params)", $service);
        }
        foreach (['短剧提示词配置', '恢复默认', 'prompt_config_definitions', 'prompt_config_values'] as $label) {
            self::assertStringContainsString($label, $bundle);
        }
        self::assertStringNotContainsString('label:"单集剧本系统提示词"', $basicConfigBundle);
        self::assertStringNotContainsString('script_system_prompt:l.script_system_prompt', $basicConfigBundle);

        self::assertStringContainsString(
            "\$promptConfig = self::scriptPromptConfig(\$tenantId, \$episodeSettings['multi_episode'])",
            $service
        );
        self::assertStringContainsString("'script_prompt_defaults'", $service);
    }

    private function invoke(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(AigcShortDramaService::class, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs(null, $arguments);
    }
}
