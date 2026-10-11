<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class PlatformCopyrightSettingContractTest extends TestCase
{
    public function testWebsiteInformationReadsAndSavesPlatformCopyright(): void
    {
        $root = dirname(__DIR__, 2);
        $logic = (string)file_get_contents($root . '/app/platformapi/logic/setting/web/WebSettingLogic.php');
        $validator = (string)file_get_contents($root . '/app/platformapi/validate/setting/WebSettingValidate.php');

        self::assertStringContainsString(
            "'copyright_config' => ConfigService::get('copyright', 'config', [])",
            $logic
        );
        self::assertStringContainsString(
            "array_key_exists('copyright_config', \$params)",
            $logic
        );
        self::assertStringContainsString(
            "ConfigService::set('copyright', 'config', \$params['copyright_config'])",
            $logic
        );
        self::assertStringContainsString("'copyright_config' => 'array'", $validator);
        self::assertStringContainsString("'point_unit', 'copyright_config'", $validator);
    }

    public function testCompiledWebsiteInformationExposesCopyrightEditor(): void
    {
        $page = \Tests\Support\CompiledAssets::vitePage('platform', 'setting/website/information');
        $source = \Tests\Support\CompiledAssets::source('platform/src/views/setting/website/information.vue');
        $login = \Tests\Support\CompiledAssets::read('platform', 'login', 'js');
        foreach (['底部版权信息', '显示名称', '跳转链接', 'copyright_config', '请输入链接，例如：http://www.beian.gov.cn'] as $label) {
            self::assertStringContainsString($label, $page);
        }
        self::assertStringContainsString('rows.slice(0, 1)', $source);
        self::assertStringContainsString('padding-bottom: 96px', $source);
        self::assertStringNotContainsString('添加版权信息', $page);
        self::assertStringContainsString('copyright_config', $login);
    }
}
