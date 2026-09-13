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

    public function testMultiEpisodeTemplateRendersStageAndEpisodeContext(): void
    {
        $rendered = $this->invoke(
            'renderScriptPlanPromptTemplate',
            implode("\n", [
                '{{default_prompt}}',
                'stage={{multi_episode_stage}}/{{multi_episode_stage_label}}',
                'instruction={{stage_instruction}}',
                'count={{episode_count}}/{{episode_total_count}}',
                'batch={{episode_batch_start}}-{{episode_batch_end}}',
                'context={{episode_batch_context}}',
                'idea={{prompt}}',
            ]),
            'DEFAULT',
            'User idea',
            [
                'multi_episode' => true,
                'episode_count' => 5,
                'episode_total_count' => 20,
                'episode_batch_start' => 6,
                'episode_batch_end' => 10,
                'multi_episode_stage' => 'episodes',
                'episode_batch_context' => '上一批次结尾：雨停',
            ],
            'Series title'
        );

        self::assertStringContainsString('stage=episodes/分集大纲', $rendered);
        self::assertStringContainsString('count=5/20', $rendered);
        self::assertStringContainsString('batch=6-10', $rendered);
        self::assertStringContainsString('context=上一批次结尾：雨停', $rendered);
        self::assertStringContainsString('idea=User idea', $rendered);
        self::assertStringNotContainsString('{{', $rendered);
    }

    public function testPromptTemplateSerializesArrayContextWithoutPhpCastWarnings(): void
    {
        $rendered = $this->invoke(
            'renderScriptPlanPromptTemplate',
            '{{default_prompt}}\ncontext={{episode_batch_context}}\nrevision={{revision_message}}',
            'DEFAULT',
            'User idea',
            [
                'multi_episode' => true,
                'episode_count' => 3,
                'episode_batch_context' => ['last_hook' => '雨停', 'known_clue' => '旧钥匙'],
                'revision_message' => ['focus' => '强化结尾钩子'],
            ],
            'Series title'
        );

        self::assertStringContainsString('"last_hook":"雨停"', $rendered);
        self::assertStringContainsString('"focus":"强化结尾钩子"', $rendered);
        self::assertStringNotContainsString('{{', $rendered);
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
        self::assertSame('{{default_prompt}}', $this->invoke(
            'ensurePlanningPromptTemplateContract',
            '{ default_prompt }',
            '{{default_prompt}}'
        ));
    }

    public function testTemplatePlaceholderContractRejectsUnknownVariables(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('不支持的变量');

        $this->invoke(
            'validatePromptTemplatePlaceholders',
            '{{default_prompt}} {{not_a_real_variable}}',
            ['{{default_prompt}}'],
            '多集剧本生成提示词模板',
            ['{{default_prompt}}']
        );
    }

    public function testTemplatePlaceholderContractRejectsMalformedVariableNames(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('不支持的变量');

        $this->invoke(
            'validatePromptTemplatePlaceholders',
            '{{default_prompt}} {{episode-count}}',
            ['{{default_prompt}}'],
            '多集剧本生成提示词模板',
            ['{{default_prompt}}']
        );
    }

    public function testTemplatePlaceholderContractRejectsMalformedLegacySingleBraceNames(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('不支持的变量');

        $this->invoke(
            'validatePromptTemplatePlaceholders',
            '{{default_prompt}} {episode-count}',
            ['{{default_prompt}}'],
            '多集剧本生成提示词模板',
            ['{{default_prompt}}']
        );
    }

    public function testSystemPromptMayDocumentSingleBraceSchemaExamples(): void
    {
        $systemPrompt = $this->invoke('scriptPlanSystemPrompt');
        $validated = $this->invoke(
            'validatePromptTemplatePlaceholders',
            $systemPrompt,
            [],
            '剧本系统提示词',
            [],
            false
        );
        self::assertSame($systemPrompt, $validated);
    }

    public function testGenerationTemplateRestoresPromptWhenTenantOmittedIt(): void
    {
        self::assertSame(
            "自定义包装\n\n{{prompt}}",
            $this->invoke('ensureGenerationPromptTemplateContract', '自定义包装')
        );

        $rendered = $this->invoke(
            'renderGenerationPromptTemplate',
            '镜头={{shot_type}}，集={{episode_number}}',
            '完整基础提示词',
            ['shot_type' => '近景', 'episode_number' => '2']
        );
        self::assertStringContainsString('镜头=近景，集=2', $rendered);
        self::assertStringContainsString('完整基础提示词', $rendered);

        self::assertSame(
            '完整基础提示词',
            $this->invoke('renderGenerationPromptTemplate', '{ prompt }', '完整基础提示词')
        );
    }

    public function testGenerationTemplateSubjectContextCanBeResolvedFromShotReferences(): void
    {
        self::assertSame(
            '林浅、顾言',
            $this->invoke('shotPromptSubjectName',
                ['subject_ref_ids' => ['subject_1', 'subject_2']],
                ['subjects' => [
                    ['id' => 'subject_1', 'name' => '林浅'],
                    ['id' => 'subject_2', 'name' => '顾言'],
                ]]
            )
        );
    }

    public function testHistoricalUnknownGenerationTemplateFallsBackToSafeDefault(): void
    {
        $effective = $this->invoke('effectiveRuntimePromptConfig', [
            'prompt_config' => [
                'global_system_prompt' => '旧追加规则 {{removed_variable}}',
                'shot_image_prompt_template' => '旧模板 {{removed_variable}}',
                'shot_video_prompt_template' => '有效模板 {{prompt}} / {{duration}}',
            ],
        ]);

        self::assertSame('', $effective['global_system_prompt']);
        self::assertSame('{{prompt}}', $effective['shot_image_prompt_template']);
        self::assertSame('有效模板 {{prompt}} / {{duration}}', $effective['shot_video_prompt_template']);
    }

    public function testHistoricalUnknownPlanningTemplateFallsBackToSafeDefault(): void
    {
        self::assertSame(
            '{{default_prompt}}',
            $this->invoke('effectivePlanningPromptTemplate', '{{default_prompt}} {{removed_variable}}', '{{default_prompt}}', '单集剧本生成提示词模板')
        );
        self::assertSame(
            "这是多集短剧任务。请优先保证跨集连续性、准确集数和每集结尾钩子。\n{{default_prompt}}",
            $this->invoke('effectivePlanningPromptTemplate', '{{default_prompt}} {{removed_variable}}', "这是多集短剧任务。请优先保证跨集连续性、准确集数和每集结尾钩子。\n{{default_prompt}}", '多集剧本生成提示词模板')
        );
    }

    public function testHistoricalUnknownSystemPromptFallsBackBeforeProviderRequest(): void
    {
        self::assertSame(
            $this->invoke('scriptPlanSystemPrompt'),
            $this->invoke('effectiveSystemPromptValue', '旧系统规则 {{removed_variable}}', $this->invoke('scriptPlanSystemPrompt'), '剧本系统提示词')
        );
        self::assertSame(
            $this->invoke('multiEpisodeScriptPlanSystemPrompt'),
            $this->invoke('effectiveSystemPromptValue', '旧多集规则 {{removed_variable}}', $this->invoke('multiEpisodeScriptPlanSystemPrompt'), '多集剧本系统提示词')
        );
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
