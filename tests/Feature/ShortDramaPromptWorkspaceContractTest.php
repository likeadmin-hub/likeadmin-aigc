<?php

namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ShortDramaPromptWorkspaceContractTest extends TestCase
{
    public function testPromptWorkspaceCoversEveryShortDramaStage(): void
    {
        $definitions = $this->invoke('promptConfigDefinitions');
        self::assertSame(
            ['general', 'script', 'subject', 'scene', 'storyboard'],
            array_column($definitions, 'key')
        );

        $items = [];
        foreach ($definitions as $definition) {
            foreach ($definition['items'] as $item) {
                $items[$item['key']] = $item;
            }
        }
        foreach ([
            'global_system_prompt',
            'script_system_prompt',
            'script_prompt_template',
            'multi_episode_script_system_prompt',
            'multi_episode_script_prompt_template',
            'subject_planning_prompt',
            'subject_image_prompt_template',
            'three_view_prompt_template',
            'scene_planning_prompt',
            'scene_image_prompt_template',
            'storyboard_planning_prompt',
            'shot_image_prompt_template',
            'shot_video_prompt_template',
        ] as $key) {
            self::assertArrayHasKey($key, $items);
        }
        foreach ([
            'subject_image_prompt_template',
            'three_view_prompt_template',
            'scene_image_prompt_template',
            'shot_image_prompt_template',
            'shot_video_prompt_template',
        ] as $key) {
            self::assertContains('{{prompt}}', $items[$key]['variables']);
            self::assertStringContainsString('{{prompt}}', $items[$key]['default']);
        }
        foreach (['script_prompt_template', 'multi_episode_script_prompt_template'] as $key) {
            foreach ([
                '{{default_prompt}}',
                '{{multi_episode_stage}}',
                '{{multi_episode_stage_label}}',
                '{{episode_count}}',
                '{{episode_total_count}}',
                '{{episode_batch_context}}',
            ] as $variable) {
                self::assertContains($variable, $items[$key]['variables']);
            }
            self::assertStringContainsString('{{default_prompt}}', $items[$key]['default']);
        }
    }

    public function testGenerationTemplateRendersContextAndGlobalRequirements(): void
    {
        $rendered = $this->invoke(
            'renderGenerationPromptTemplate',
            "任务={{task_type}}\n场景={{scene_name}}\n时长={{duration}}\n{{prompt}}",
            '完整基础提示词',
            ['task_type' => 'shot_video', 'scene_name' => '天台', 'duration' => '8'],
            '统一品牌规范'
        );

        self::assertStringStartsWith('统一品牌规范', $rendered);
        self::assertStringContainsString('任务=shot_video', $rendered);
        self::assertStringContainsString('场景=天台', $rendered);
        self::assertStringContainsString('时长=8', $rendered);
        self::assertStringContainsString('完整基础提示词', $rendered);
        self::assertStringNotContainsString('{{', $rendered);
    }

    public function testPlanningPromptAppendsConfiguredStageRequirements(): void
    {
        $rendered = $this->invoke('appendPlanningPromptConfig', 'BASE', [
            'global_system_prompt' => '统一要求',
            'subject_planning_prompt' => '主体要求',
            'scene_planning_prompt' => '场景要求',
            'storyboard_planning_prompt' => '分镜要求',
        ]);

        foreach (['BASE', '租户全局要求', '统一要求', '主体规划补充要求', '场景规划补充要求', '分镜规划补充要求'] as $text) {
            self::assertStringContainsString($text, $rendered);
        }
    }

    public function testPromptPageMenuRouteAndRuntimeHooksAreRegistered(): void
    {
        $root = dirname(__DIR__, 2);
        $menus = json_decode((string)file_get_contents(
            $root . '/app/apps/aigc_short_drama/menus/tenant.json'
        ), true, 512, JSON_THROW_ON_ERROR);
        $children = $menus[0]['children'] ?? [];
        $promptMenu = null;
        foreach ($children as $child) {
            if (($child['source_menu_key'] ?? '') === 'aigc_short_drama_prompt') {
                $promptMenu = $child;
                break;
            }
        }
        self::assertNotNull($promptMenu);
        self::assertSame('提示词配置', $promptMenu['name']);
        self::assertSame(6, $promptMenu['sort']);
        self::assertSame('apps/aigc_short_drama/prompt', $promptMenu['component']);

        $tenantRoot = dirname($root) . '/tenant';
        $router = (string)file_get_contents($tenantRoot . '/src/router/index.ts');
        self::assertStringContainsString("import.meta.glob('/src/views/**/*.vue')", $router);
        $promptPage = (string)file_get_contents($tenantRoot . '/src/views/apps/aigc_short_drama/prompt.vue');
        self::assertStringContainsString('prompt_config_definitions', $promptPage);
        self::assertStringContainsString('prompt_config_values', $promptPage);
        self::assertStringContainsString('setAigcShortDramaConfig({ prompt_config:', $promptPage);

        $service = (string)file_get_contents(
            $root . '/app/common/service/app/aigc_short_drama/AigcShortDramaService.php'
        );
        self::assertStringContainsString("self::applyConfiguredGenerationPromptTemplate(\$tenantId, \$templateKey", $service);
        self::assertStringContainsString("self::applyConfiguredGenerationPromptTemplate(\$tenantId, 'shot_video_prompt_template'", $service);
        self::assertStringContainsString('self::appendPlanningPromptConfig(', $service);

        $upgrade = (string)file_get_contents($root . '/upgrade/20260820_short_drama_prompt_config.sql');
        $publicUpgrade = (string)file_get_contents($root . '/public/upgrade/20260820_short_drama_prompt_config.sql');
        self::assertSame($upgrade, $publicUpgrade);
        self::assertStringContainsString("'aigc_short_drama_prompt'", $upgrade);
        self::assertStringContainsString('INSERT IGNORE INTO `la_tenant_system_role_menu`', $upgrade);
    }

    private function invoke(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(AigcShortDramaService::class, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs(null, $arguments);
    }
}
