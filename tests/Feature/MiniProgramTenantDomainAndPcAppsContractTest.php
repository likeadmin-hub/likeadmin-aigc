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

    public function testPcToolRegistryIncludesTheNewNonCommerceApplications(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/pc/composables/use-ai-tools.ts');

        foreach (['aigc_watermark_removal', 'aigc_music_cover'] as $appCode) {
            self::assertStringContainsString("appCode: '{$appCode}'", $source);
            self::assertStringContainsString("appPath: '/ai/tools/{$appCode}'", $source);
        }
    }
}
