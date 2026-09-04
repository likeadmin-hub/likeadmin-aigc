<?php

namespace tests\Feature;

use PHPUnit\Framework\TestCase;

class TenantDomainAliasValidationContractTest extends TestCase
{
    public function testTenantAliasesRejectThePlatformHostTreeAndSyncOnEdit(): void
    {
        $urlService = file_get_contents(dirname(__DIR__, 2) . '/app/common/service/tenant/TenantUrlService.php');
        $aliasService = file_get_contents(dirname(__DIR__, 2) . '/app/common/service/tenant/TenantDomainAliasService.php');
        $tenantLogic = file_get_contents(dirname(__DIR__, 2) . '/app/platformapi/logic/tenant/TenantLogic.php');
        $ssoService = file_get_contents(dirname(__DIR__, 2) . '/app/common/service/tenant/TenantSsoService.php');

        self::assertStringContainsString('public static function isPlatformHost', $urlService);
        self::assertStringContainsString('TenantUrlService::isPlatformHost($domain, $requestHost)', $aliasService);
        self::assertStringContainsString('TenantDomainAliasService::syncTenantAliases($tenantId, $aliases, $domain_alias, true)', $tenantLogic);
        self::assertStringContainsString("'domain_alias'        => " . '$' . "domain_alias", $tenantLogic);
        self::assertStringContainsString("'tenant_id_query'", $urlService);
        self::assertStringContainsString("'tenant_id_path'", $urlService);
        self::assertStringContainsString("where('tenant_id', " . '$tenantId' . ")->delete()", $aliasService);
        self::assertStringContainsString("->whereNull('delete_time')", $aliasService);
        self::assertStringContainsString('TenantUrlService::attach($tenantData)', $ssoService);
        self::assertStringContainsString("" . '$tenantLinks' . "['links']['current']['admin']", $ssoService);
    }
}
