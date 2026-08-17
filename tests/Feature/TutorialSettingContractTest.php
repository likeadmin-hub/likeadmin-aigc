<?php

namespace Tests\Feature;

use app\platformapi\validate\setting\WebSettingValidate;
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

    public function testPublicConfigAndPlatformMenuExposeTheTutorialContract(): void
    {
        $root = dirname(__DIR__, 2);
        $pcLogic = (string)file_get_contents($root . '/app/api/logic/PcLogic.php');
        $menuLogic = (string)file_get_contents($root . '/app/platformapi/logic/auth/MenuLogic.php');

        self::assertStringContainsString("'tutorial' => \$tutorial", $pcLogic);
        self::assertStringContainsString('TutorialConfigService::get()', $pcLogic);
        self::assertStringContainsString("'paths' => 'tutorial'", $menuLogic);
        self::assertStringContainsString('setting.web.web_setting/getTutorial', $menuLogic);
        self::assertStringContainsString('setting.web.web_setting/setTutorial', $menuLogic);
    }

    public function testTenantAdminDoesNotExposeTutorialConfiguration(): void
    {
        $root = dirname(__DIR__, 2);
        $tenantFiles = [
            '/app/tenantapi/controller/setting/web/WebSettingController.php',
            '/app/tenantapi/logic/setting/web/WebSettingLogic.php',
            '/app/tenantapi/validate/setting/WebSettingValidate.php',
            '/app/tenantapi/logic/auth/MenuLogic.php',
            '/app/platformapi/db/tenantData.sql',
            '/public/admin/assets/website-Bu78k_2q.js',
        ];

        foreach ($tenantFiles as $file) {
            $contents = (string)file_get_contents($root . $file);
            self::assertStringNotContainsString('getTutorial', $contents, $file);
            self::assertStringNotContainsString('setTutorial', $contents, $file);
            self::assertStringNotContainsString('core_tenant_tutorial', $contents, $file);
        }
    }

    public function testCompiledPlatformEntryKeepsWebsiteInformationAndTutorialPagesSeparate(): void
    {
        $root = dirname(__DIR__, 2);
        $entry = (string)file_get_contents($root . '/public/platform/assets/information-C5syF0gD.js');
        $tutorial = (string)file_get_contents($root . '/public/platform/assets/tutorial-CodexPlatformA1.js');

        self::assertStringContainsString('tutorial-CodexPlatformA1.js', $entry);
        self::assertStringContainsString('information-original-CodexPlatformA1.js', $entry);
        self::assertStringContainsString('route.meta.sourceMenuKey==="core_platform_tutorial"', $entry);
        self::assertStringContainsString('at(-1)==="tutorial"', $entry);
        self::assertStringContainsString('getTutorial', $tutorial);
        self::assertStringContainsString('setTutorial', $tutorial);
    }
}
