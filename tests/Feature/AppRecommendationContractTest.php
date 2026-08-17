<?php

namespace Tests\Feature;

use app\common\service\app\AppDisplayConfigService;
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
            ['is_recommend' => 1],
            $this->invoke('normalizeDisplayExtra', [[], ['extra' => ['is_recommend' => 1]]])
        );
        self::assertSame(
            ['is_recommend' => 0],
            $this->invoke('normalizeDisplayExtra', [['is_recommend' => 0], ['extra' => ['is_recommend' => 1]]])
        );
        self::assertSame(
            ['is_recommend' => 1],
            $this->invoke('normalizeDisplayExtra', [['extra' => ['is_recommend' => 1]], ['is_recommend' => 0]])
        );
    }

    public function testSharedAdminDisplayConfigExposesHomeRecommendationSwitch(): void
    {
        $asset = file_get_contents(dirname(__DIR__, 2) . '/public/admin/assets/app-display-config.vue_vue_type_script_setup_true_lang-BRHbShgH.js');

        self::assertIsString($asset);
        self::assertStringContainsString('is_recommend:0', $asset);
        self::assertStringContainsString('label:"首页推荐"', $asset);
        self::assertStringContainsString('r(t).is_recommend', $asset);
    }

    public function testHomeHotToolsOnlyUseRecommendedApplications(): void
    {
        $homeHook = file_get_contents(dirname(__DIR__, 2) . '/public/_nuxt/useAiPcHomeDecorate.2c44a638.js');
        $tools = file_get_contents(dirname(__DIR__, 2) . '/public/_nuxt/use-ai-tools.b80b7d43.js');
        $homePage = file_get_contents(dirname(__DIR__, 2) . '/public/_nuxt/index.de219304.js');

        self::assertIsString($homeHook);
        self::assertIsString($tools);
        self::assertIsString($homePage);
        self::assertStringContainsString('recommended:Number(e.is_recommend??e.recommend??0)===1', $homeHook);
        self::assertStringContainsString('appCode:"aigc_short_drama"', $tools);
        self::assertStringContainsString('appPath:"/ai/short-drama"', $tools);
        self::assertStringContainsString('.some(W=>(W==null?void 0:W.recommended)===!0)', $tools);
        self::assertStringContainsString('te.value.filter(e=>e.recommended)', $homePage);
    }

    public function testServerAiToolsSourceAlsoRequiresRecommendation(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/common/service/decorate/DecorateDataSourceService.php');

        self::assertIsString($source);
        self::assertStringContainsString('(int)($display[\'is_recommend\'] ?? 0) !== 1', $source);
        self::assertStringContainsString("'is_recommend' => 1", $source);
        self::assertStringContainsString("AppFrontendManifestService::tenantEntries(\$tenantId, 'pc')", $source);
    }

    private function invoke(string $method, array $arguments): mixed
    {
        $reflection = new ReflectionMethod(AppDisplayConfigService::class, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs(null, $arguments);
    }
}
