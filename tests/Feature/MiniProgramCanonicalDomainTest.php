<?php

namespace tests\Feature;

use app\common\service\wechat\OpenPlatformService;
use app\tenantapi\logic\channel\MnpSettingsLogic;
use PHPUnit\Framework\TestCase;
use think\facade\Config;
use think\facade\Db;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class MiniProgramCanonicalDomainTest extends TestCase
{
    protected function setUp(): void
    {
        (new \think\App())->initialize();
        Config::set(['default' => 'canonical_mini', 'connections' => ['canonical_mini' => [
            'type' => 'sqlite', 'database' => ':memory:', 'prefix' => 'la_',
        ]]], 'database');
        Config::set(['http_host' => 'platform.example.com'], 'project');
        Db::execute('CREATE TABLE la_tenant (id INTEGER PRIMARY KEY, sn TEXT, domain_alias TEXT, domain_alias_enable INTEGER, access_mode TEXT, delete_time INTEGER)');
        Db::execute('CREATE TABLE la_tenant_domain_alias (id INTEGER PRIMARY KEY, tenant_id INTEGER, domain TEXT, is_primary INTEGER, status INTEGER, delete_time INTEGER)');
        Db::execute('CREATE TABLE la_wechat_credentials (id INTEGER PRIMARY KEY, tenant_id INTEGER, settings_json TEXT)');
        Db::name('tenant')->insert(['id' => 7, 'sn' => 'customer', 'domain_alias' => 'old.example.com', 'domain_alias_enable' => 0, 'access_mode' => 'alias']);
        Db::name('tenant_domain_alias')->insert(['id' => 1, 'tenant_id' => 7, 'domain' => 'customer.example.com', 'is_primary' => 1, 'status' => 1]);
        Db::name('wechat_credentials')->insert(['tenant_id' => 7, 'settings_json' => '{"business_domain":"platform.example.com"}']);
        request()->tenantId = 7;
        request()->withServer(['HTTP_HOST' => 'platform.example.com', 'REQUEST_SCHEME' => 'http']);
    }

    private function runtime(int $tenantId = 7): array
    {
        $method = new \ReflectionMethod(OpenPlatformService::class, 'tenantRuntimeConfig');
        $method->setAccessible(true);
        return $method->invoke(null, $tenantId, true);
    }

    public function testCommitUsesCurrentBoundTenantDomainInsteadOfStalePlatformSettings(): void
    {
        self::assertSame('https://customer.example.com/', $this->runtime()['apiBaseUrl']);
        $method = new \ReflectionMethod(MnpSettingsLogic::class, 'resolveTenantDomain');
        $method->setAccessible(true);
        self::assertSame('customer.example.com', $method->invoke(new MnpSettingsLogic()));
    }

    public function testChangingPrimaryAliasTakesEffectWithoutResavingWechatSettings(): void
    {
        Db::name('tenant_domain_alias')->where('id', 1)->update(['domain' => 'new.example.com']);
        self::assertSame('https://new.example.com/', $this->runtime()['apiBaseUrl']);
    }

    public function testDisabledAliasUsesTenantSubdomain(): void
    {
        Db::name('tenant')->where('id', 7)->update(['domain_alias_enable' => 1]);
        self::assertSame('https://customer.platform.example.com/', $this->runtime()['apiBaseUrl']);
    }

    public function testTemplateCommitDoesNotRequireAnObsoleteSettingsSnapshot(): void
    {
        Db::name('wechat_credentials')->delete(true);
        self::assertSame('https://customer.example.com/', $this->runtime()['apiBaseUrl']);
    }

    public function testDomainRemainsScopedToTheRequestedTenant(): void
    {
        Db::name('tenant')->insert(['id' => 8, 'sn' => 'second', 'domain_alias_enable' => 1]);
        self::assertSame('https://second.platform.example.com/', $this->runtime(8)['apiBaseUrl']);
        self::assertSame('7', $this->runtime()['tenantId']);
    }
}
