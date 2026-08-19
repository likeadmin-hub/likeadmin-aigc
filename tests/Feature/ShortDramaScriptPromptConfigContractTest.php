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

    public function testAdminBundleLoadsSavesAndResetsPromptConfiguration(): void
    {
        $root = dirname(__DIR__, 2);
        $bundle = (string)file_get_contents($root . '/public/admin/assets/config-fge0of94.js');
        $service = (string)file_get_contents(
            $root . '/app/common/service/app/aigc_short_drama/AigcShortDramaService.php'
        );

        foreach (['script_system_prompt', 'script_prompt_template'] as $field) {
            self::assertGreaterThanOrEqual(5, substr_count($bundle, $field));
            self::assertStringContainsString("array_key_exists('{$field}', \$params)", $service);
        }
        foreach (['剧本系统提示词', '剧本生成提示词', '恢复默认'] as $label) {
            self::assertStringContainsString($label, $bundle);
        }
        foreach (['{{default_prompt}}', '{{user_prompt}}', '{{title}}', '{{request_json}}'] as $placeholder) {
            self::assertStringContainsString($placeholder, $bundle);
        }

        self::assertStringContainsString("\$promptConfig = self::scriptPromptConfig(\$tenantId)", $service);
        self::assertStringContainsString("'script_prompt_defaults'", $service);
    }

    private function invoke(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(AigcShortDramaService::class, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs(null, $arguments);
    }
}
