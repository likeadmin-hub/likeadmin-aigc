<?php

namespace tests\Feature;

use app\common\service\wechat\OpenPlatformService;
use PHPUnit\Framework\TestCase;
use think\facade\Config;
use think\facade\Db;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class MiniProgramUploadRuntimeTest extends TestCase
{
    protected function setUp(): void
    {
        (new \think\App())->initialize();
        Config::set(['default' => 'mini_runtime_test', 'connections' => ['mini_runtime_test' => [
            'type' => 'sqlite', 'database' => ':memory:', 'prefix' => 'la_',
            'fields_strict' => true, 'fields_cache' => false,
        ]]], 'database');
        Db::execute('CREATE TABLE la_wechat_credentials (id INTEGER PRIMARY KEY, tenant_id INTEGER, settings_json TEXT)');
        Db::execute('CREATE TABLE la_tenant (id INTEGER PRIMARY KEY, sn TEXT, domain_alias TEXT, domain_alias_enable INTEGER, delete_time INTEGER)');
        Db::execute('CREATE TABLE la_tenant_domain_alias (id INTEGER PRIMARY KEY, tenant_id INTEGER, domain TEXT, is_primary INTEGER, status INTEGER, delete_time INTEGER)');
        Config::set(['http_host' => 'platform.example.com'], 'project');
        Db::name('tenant')->insert(['id' => 7, 'sn' => 'customer', 'domain_alias' => 'tenant.example.com', 'domain_alias_enable' => 0]);
        request()->withServer(['HTTP_HOST' => 'tenant.example.com', 'REQUEST_SCHEME' => 'http', 'SERVER_PORT' => 80]);
    }

    private function runtime(int $tenantId = 7, bool $requireConfiguredDomain = false): array
    {
        $method = new \ReflectionMethod(OpenPlatformService::class, 'tenantRuntimeConfig');
        $method->setAccessible(true);
        return $method->invoke(null, $tenantId, $requireConfiguredDomain);
    }

    private function domain(string $domain, int $tenantId = 7): void
    {
        $binding = ['id' => $tenantId, 'sn' => 'customer' . $tenantId, 'domain_alias' => $domain, 'domain_alias_enable' => 0];
        if (Db::name('tenant')->where('id', $tenantId)->find()) {
            Db::name('tenant')->where('id', $tenantId)->update($binding);
        } else Db::name('tenant')->insert($binding);
    }

    public function testManualUploadUsesHttpsEvenBehindAnHttpReverseProxy(): void
    {
        $runtime = $this->runtime();
        self::assertSame('https://tenant.example.com/', $runtime['apiBaseUrl']);
        self::assertSame('7', $runtime['tenantId']);
        self::assertSame(7, $runtime['tenant_id']);
    }

    public function testBoundDomainIsTenantScopedAndSupportsBothUploadModes(): void
    {
        $this->domain('customer.example.com');
        $this->domain('https://another.example.com/', 8);
        self::assertSame('https://customer.example.com/', $this->runtime()['apiBaseUrl']);
        self::assertSame('https://another.example.com/', $this->runtime(8, true)['apiBaseUrl']);
    }

    /** @dataProvider invalidDomains */
    public function testFormalUploadsRejectUnusableApiDomains(string $domain): void
    {
        $this->domain($domain);
        $this->expectException(\RuntimeException::class);
        $this->runtime();
    }

    public static function invalidDomains(): array
    {
        return array_map(static fn($domain) => [$domain], [
            '127.0.0.1', 'localhost', '192.168.1.2', 'tenant.localhost',
            '__TENANT_API_BASE_URL__',
        ]);
    }

    public function testUploadNeverFallsBackToTheLoopbackRequestHost(): void
    {
        request()->setHost('127.0.0.1:5173');
        self::assertSame('https://tenant.example.com/', $this->runtime()['apiBaseUrl']);
    }

    public function testObsoleteSettingsCannotOverrideTheBoundDomain(): void
    {
        Db::name('wechat_credentials')->insert(['tenant_id' => 7, 'settings_json' => '{"business_domain":"https://platform.example.com:8443"}']);
        self::assertSame('https://tenant.example.com/', $this->runtime()['apiBaseUrl']);
    }

    public function testTemplateModeRejectsAnUnknownTenantInsteadOfUsingTheRequestHost(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->runtime(999, true);
    }

    public function testInjectionChangesOnlyTheTenantCopyAndPreservesSharedPlaceholders(): void
    {
        $this->domain('customer.example.com');
        $shared = dirname(__DIR__, 2) . '/public/mp-weixin/config/runtime.js';
        $source = file_get_contents($shared);
        $workspace = sys_get_temp_dir() . '/mini-runtime-test-' . bin2hex(random_bytes(8));
        mkdir($workspace . '/config', 0700, true);
        $copy = $workspace . '/config/runtime.js';
        try {
            file_put_contents($copy, $source);
            $method = new \ReflectionMethod(OpenPlatformService::class, 'replaceTenantRuntimeMarkers');
            $method->setAccessible(true);
            $method->invoke(null, $workspace, $this->runtime());
            $injected = file_get_contents($copy);
            self::assertStringContainsString('https://customer.example.com/', $injected);
            self::assertStringNotContainsString('__TENANT_API_BASE_URL__', $injected);
            self::assertStringNotContainsString('__TENANT_ID__', $injected);
            self::assertSame($source, file_get_contents($shared));
        } finally {
            unlink($copy);
            rmdir($workspace . '/config');
            rmdir($workspace);
        }
    }
}
