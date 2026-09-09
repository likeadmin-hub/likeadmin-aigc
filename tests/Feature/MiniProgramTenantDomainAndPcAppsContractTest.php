<?php

namespace tests\Feature;

use PHPUnit\Framework\TestCase;

class MiniProgramTenantDomainAndPcAppsContractTest extends TestCase
{
    public function testMiniProgramSettingsResolveTheTenantHostInsteadOfProxyServerName(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/tenantapi/logic/channel/MnpSettingsLogic.php');

        self::assertStringContainsString('private function resolveTenantDomain()', $source);
        self::assertStringContainsString('TenantUrlService::links($tenantData)', $source);
        self::assertStringContainsString("\$_SERVER['HTTP_HOST'] ?? request()->domain()", $source);
        self::assertStringNotContainsString("\$domainName = \$_SERVER['SERVER_NAME'];", $source);
    }

    public function testManualUploadKeepsTheProviderErrorTailAndReturnsStructuredDiagnostics(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/common/service/wechat/OpenPlatformService.php');

        self::assertStringContainsString("WECHAT_UPLOAD_ERROR:", $source);
        self::assertStringContainsString('extractManualUploadDiagnostic', $source);
        self::assertStringContainsString('mb_substr($output, -16000)', $source);
        self::assertStringContainsString('mb_substr($buffer . $chunk, -32768)', $source);
        self::assertStringContainsString("'error_summary'", $source);
        self::assertStringContainsString("'log_tail'", $source);
    }

    public function testMiniProgramBuildHasAWechatLegacySyntaxCompatibilityPass(): void
    {
        $package = json_decode(file_get_contents(dirname(__DIR__, 3) . '/uniapp/package.json'), true);

        self::assertIsArray($package);
        self::assertStringContainsString('ensure-mp-weixin-legacy-syntax.mjs', $package['scripts']['build:mp-weixin'] ?? '');
        self::assertFileExists(dirname(__DIR__, 3) . '/uniapp/scripts/ensure-mp-weixin-legacy-syntax.mjs');
    }

    public function testPcToolRegistryIncludesTheNewNonCommerceApplications(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/pc/composables/use-ai-tools.ts');

        foreach (['aigc_watermark_removal', 'aigc_music_cover'] as $appCode) {
            self::assertStringContainsString("appCode: '{$appCode}'", $source);
            self::assertStringContainsString("appPath: '/ai/tools/{$appCode}'", $source);
        }
    }
}
