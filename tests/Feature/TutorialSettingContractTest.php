<?php

namespace Tests\Feature;

use app\tenantapi\validate\setting\WebSettingValidate;
use PHPUnit\Framework\TestCase;

class TutorialSettingContractTest extends TestCase
{
    public function testTutorialUrlValidationOnlyAllowsHttpSchemes(): void
    {
        $validator = new WebSettingValidate();

        self::assertTrue($validator->checkTutorialUrl('https://example.test/tutorial'));
        self::assertTrue($validator->checkTutorialUrl('http://example.test/tutorial'));
        self::assertNotTrue($validator->checkTutorialUrl('javascript:alert(1)'));
        self::assertNotTrue($validator->checkTutorialUrl('not-a-url'));
    }

    public function testPublicConfigAndTenantMenuExposeTheTutorialContract(): void
    {
        $root = dirname(__DIR__, 2);
        $pcLogic = (string)file_get_contents($root . '/app/api/logic/PcLogic.php');
        $menuLogic = (string)file_get_contents($root . '/app/tenantapi/logic/auth/MenuLogic.php');
        $controller = (string)file_get_contents($root . '/app/tenantapi/controller/setting/web/WebSettingController.php');
        $logic = (string)file_get_contents($root . '/app/tenantapi/logic/setting/web/WebSettingLogic.php');
        $service = (string)file_get_contents($root . '/app/common/service/TutorialConfigService.php');

        self::assertStringContainsString("'tutorial' => \$tutorial", $pcLogic);
        self::assertStringContainsString('TutorialConfigService::get()', $pcLogic);
        self::assertStringContainsString('ConfigService::get(self::TYPE', $service);
        self::assertStringContainsString('ConfigService::set(self::TYPE', $service);
        self::assertStringContainsString('core_tenant_tutorial', $menuLogic);
        self::assertStringContainsString('systemSettingMenuId', $menuLogic);
        self::assertStringContainsString("'paths' => 'tutorial'", $menuLogic);
        self::assertStringContainsString('setting.web.web_setting/getTutorial', $menuLogic);
        self::assertStringContainsString('setting.web.web_setting/setTutorial', $menuLogic);
        self::assertStringContainsString('getTutorial', $controller);
        self::assertStringContainsString('setTutorial', $controller);
        self::assertStringContainsString('TutorialConfigService::get()', $logic);
        self::assertStringContainsString('TutorialConfigService::save($params)', $logic);
    }

    public function testPlatformMenuNoLongerExposesTutorialConfiguration(): void
    {
        $root = dirname(__DIR__, 2);
        $menuLogic = (string)file_get_contents($root . '/app/platformapi/logic/auth/MenuLogic.php');
        $installSql = (string)file_get_contents($root . '/public/install/db/like.sql');
        $upgradeSql = (string)file_get_contents($root . '/upgrade/20260815_platform_tutorial.sql');
        $publicUpgradeSql = (string)file_get_contents($root . '/public/upgrade/20260815_platform_tutorial.sql');
        $tenantDataSql = (string)file_get_contents($root . '/app/platformapi/db/tenantData.sql');

        self::assertStringContainsString('removeTutorialMenu', $menuLogic);
        self::assertStringNotContainsString('ensureTutorialMenu', $menuLogic);
        self::assertStringNotContainsString('core_platform_tutorial', $installSql);
        self::assertSame($upgradeSql, $publicUpgradeSql);
        self::assertStringContainsString('DELETE FROM `la_system_menu`', $upgradeSql);
        self::assertStringContainsString('core_tenant_tutorial', $upgradeSql);
        self::assertStringContainsString('core_tenant_tutorial', $tenantDataSql);
    }

    public function testCompiledTenantEntryKeepsWebsiteInformationAndTutorialPagesSeparate(): void
    {
        $root = dirname(__DIR__, 2);
        $entry = (string)file_get_contents($root . '/public/admin/assets/information-CDL0NXGN.js');
        $tutorial = (string)file_get_contents($root . '/public/admin/assets/tutorial-CodexTenantA1.js');
        $websiteApi = (string)file_get_contents($root . '/public/admin/assets/website-Bu78k_2q.js');

        self::assertStringContainsString('tutorial-CodexTenantA1.js', $entry);
        self::assertStringContainsString('information-original-CodexTenantA1.js', $entry);
        self::assertStringContainsString('route.meta.sourceMenuKey==="core_tenant_tutorial"', $entry);
        self::assertStringContainsString('at(-1)==="tutorial"', $entry);
        self::assertStringContainsString('getTutorial', $tutorial);
        self::assertStringContainsString('setTutorial', $tutorial);
        self::assertStringContainsString('getTutorial', $websiteApi);
        self::assertStringContainsString('setTutorial', $websiteApi);
    }
}
