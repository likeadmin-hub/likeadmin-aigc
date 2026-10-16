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
        Db::name('wechat_credentials')->insert(['tenant_id' => $tenantId, 'settings_json' => json_encode(['business_domain' => $domain])]);
    }

    public function testManualUploadUsesHttpsEvenBehindAnHttpReverseProxy(): void
    {
        $runtime = $this->runtime();
        self::assertSame('https://tenant.example.com/', $runtime['apiBaseUrl']);
        self::assertSame('7', $runtime['tenantId']);
        self::assertSame(7, $runtime['tenant_id']);
    }

    public function testConfiguredDomainIsTenantScopedAndSupportsBothUploadModes(): void
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
            'http://tenant.example.com', 'https://127.0.0.1:5173', 'https://localhost',
            'https://192.168.1.2', 'https://[::1]', 'https://tenant.example.com/api',
            'https://tenant.example.com?debug=1', 'https://user:pass@tenant.example.com',
            '__TENANT_API_BASE_URL__',
        ]);
    }

    public function testLoopbackRequestHostCannotBeUploadedAsAFormalPackage(): void
    {
        request()->setHost('127.0.0.1:5173');
        $this->expectException(\RuntimeException::class);
        $this->runtime();
    }

    public function testConfiguredHttpsPortIsPreserved(): void
    {
        $this->domain('https://tenant.example.com:8443');
        self::assertSame('https://tenant.example.com:8443/', $this->runtime()['apiBaseUrl']);
    }

    public function testTemplateModeStillRequiresAnExplicitDomain(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->runtime(7, true);
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
