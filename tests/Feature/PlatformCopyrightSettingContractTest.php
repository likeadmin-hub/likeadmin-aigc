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
        $root = dirname(__DIR__, 2);
        $html = (string)file_get_contents($root . '/public/platform/index.html');
        $entry = (string)file_get_contents(
            $root . '/public/platform/assets/information-C5syF0gD.js'
        );
        $page = (string)file_get_contents(
            $root . '/public/platform/assets/information-original-CodexPlatformA1.js'
        );
        $login = (string)file_get_contents($root . '/public/platform/assets/login-pO1KZzND.js');

        self::assertStringContainsString('index-DH0WGi9f.js', $html);
        self::assertStringNotContainsString('index-CodexCopyrightA2.js', $html);
        self::assertStringContainsString('information-original-CodexPlatformA1.js', $entry);
        self::assertStringContainsString('底部版权信息', $page);
        self::assertStringContainsString('import { _ as FooterBtns }', $page);
        self::assertStringContainsString('import { _ as MaterialPicker }', $page);
        self::assertStringNotContainsString('import FooterBtns from', $page);
        self::assertStringNotContainsString('import MaterialPicker from', $page);
        self::assertStringContainsString('copyright_config', $page);
        self::assertStringContainsString('显示名称', $page);
        self::assertStringContainsString('跳转链接', $page);
        self::assertStringNotContainsString('添加版权信息', $page);
        self::assertStringNotContainsString('{ default: () => "删除" }', $page);
        self::assertStringContainsString('请输入链接，例如：http://www.beian.gov.cn', $page);
        self::assertStringContainsString('normalizeCopyrightConfig', $page);
        self::assertStringContainsString('const singleRow = rows.slice(0, 1)', $page);
        self::assertStringContainsString('copyright_config: [emptyCopyrightRow()]', $page);
        self::assertStringContainsString('renderCopyrightRow', $page);
        self::assertStringNotContainsString('ensureCopyrightRows().push(emptyCopyrightRow())', $page);
        self::assertStringContainsString('paddingBottom: "96px"', $page);
        self::assertStringContainsString('copyright_config', $login);
    }
}
