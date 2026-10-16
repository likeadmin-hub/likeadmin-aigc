<?php

namespace Tests\Feature;

use app\common\service\app\AppDisplayConfigService;
use app\common\service\app\AppAccessService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class AppRecommendationContractTest extends TestCase
{
    public function testRecommendationIsPersistedInDisplayExtra(): void
    {
        self::assertSame(
            1,
            $this->invoke('recommendationValue', [['is_recommend' => 1]])
        );
        self::assertSame(
            1,
            $this->invoke('recommendationValue', [['recommend' => 1]])
        );
        self::assertSame(
            ['is_recommend' => 1, 'skill_preview_images' => [], 'skill_preview_videos' => []],
            $this->invoke('normalizeDisplayExtra', [[], ['extra' => ['is_recommend' => 1]]])
        );
        self::assertSame(
            ['is_recommend' => 0, 'skill_preview_images' => [], 'skill_preview_videos' => []],
            $this->invoke('normalizeDisplayExtra', [['is_recommend' => 0], ['extra' => ['is_recommend' => 1]]])
        );
        self::assertSame(
            ['is_recommend' => 1, 'skill_preview_images' => [], 'skill_preview_videos' => []],
            $this->invoke('normalizeDisplayExtra', [['extra' => ['is_recommend' => 1]], ['is_recommend' => 0]])
        );
    }

    public function testSharedAdminDisplayConfigExposesHomeRecommendationSwitch(): void
    {
        $asset = \Tests\Support\CompiledAssets::read('admin', 'app-display-config.vue_vue_type_script_setup_true_lang', 'js');

        self::assertIsString($asset);
        self::assertStringContainsString('is_recommend:0', $asset);
        self::assertStringContainsString('label:"首页推荐"', $asset);
        self::assertStringContainsString('v-model="formData.is_recommend"', \Tests\Support\CompiledAssets::source('tenant/src/views/apps/components/app-display-config.vue'));
    }

    public function testHomeHotToolsOnlyUseRecommendedApplications(): void
    {
        $homeHook = \Tests\Support\CompiledAssets::source('pc/composables/useAiPcHomeDecorate.ts');
        $tools = \Tests\Support\CompiledAssets::source('pc/composables/use-ai-tools.ts');
        $homePage = \Tests\Support\CompiledAssets::source('pc/components/ai-workspace/app-home.vue');
        self::assertStringContainsString('recommended: Number(item.is_recommend ?? item.recommend ?? 0) === 1', $homeHook);
        self::assertStringContainsString('appCode: \'aigc_short_drama\'', $tools);
        self::assertStringContainsString('appPath: \'/ai/short-drama\'', $tools);
        self::assertStringContainsString('.some((candidate) => candidate?.recommended === true)', $tools);
        self::assertStringContainsString('displayToolCards.value.filter((item) => item.recommended).slice(0, 12)', $homePage);
        self::assertStringContainsString('recommended:', \Tests\Support\CompiledAssets::read('pc', 'useAiPcHomeDecorate'));
        self::assertNotEmpty(\Tests\Support\CompiledAssets::pcPage('ai/index'));
    }

    public function testServerAiToolsSourceAlsoRequiresRecommendation(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/common/service/decorate/DecorateDataSourceService.php');

        self::assertIsString($source);
        self::assertStringContainsString('(int)($display[\'is_recommend\'] ?? 0) !== 1', $source);
        self::assertStringContainsString("'is_recommend' => 1", $source);
        self::assertStringContainsString("AppFrontendManifestService::tenantEntries(\$tenantId, 'pc')", $source);
    }

    public function testGeoIsAvailableForTenantDisplayManagement(): void
    {
        self::assertContains('aigc_geo', AppDisplayConfigService::DEFAULT_APP_CODES);
        self::assertContains('aigc_geo', AppDisplayConfigService::appCodes());
        self::assertNotContains('aigc_geo', AppAccessService::DEFAULT_APP_CODES);

        $default = $this->invoke('defaultConfig', ['aigc_geo']);
        self::assertSame('GEO营销优化系统', $default['title']);
        self::assertSame(78, $default['sort']);
        self::assertSame(1, $default['status']);

        $source = file_get_contents(dirname(__DIR__, 2) . '/app/tenantapi/controller/AppController.php');
        self::assertIsString($source);
        self::assertStringContainsString('DISPLAY_MANAGED_APP_CODES', $source);
        self::assertStringContainsString("'aigc_geo'", $source);
        self::assertStringContainsString('AppDisplayConfigService::save', $source);

        $myAppAsset = \Tests\Support\CompiledAssets::vitePage('admin', 'app/my/index');
        $marketAsset = \Tests\Support\CompiledAssets::vitePage('admin', 'app/market/index');
        self::assertIsString($myAppAsset);
        self::assertIsString($marketAsset);
        self::assertStringContainsString('can_renew', $myAppAsset);
        self::assertStringContainsString('can_renew', $marketAsset);
    }

    private function invoke(string $method, array $arguments): mixed
    {
        $reflection = new ReflectionMethod(AppDisplayConfigService::class, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs(null, $arguments);
    }
}
