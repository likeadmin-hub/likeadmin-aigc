<?php

namespace tests\Feature;

use app\common\http\middleware\LikeAdminAllowMiddleware;
use app\common\service\tenant\TenantDomainAliasService;
use PHPUnit\Framework\TestCase;
use think\facade\Config;
use think\facade\Db;
use think\Request;
use think\Response;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class PlatformDomainAccessTest extends TestCase
{
    protected function setUp(): void
    {
        (new \think\App())->initialize();
        Config::set(['default' => 'host_access_test', 'connections' => ['host_access_test' => [
            'type' => 'sqlite', 'database' => ':memory:', 'prefix' => 'la_',
            'fields_strict' => true, 'fields_cache' => false,
        ]]], 'database');
        Config::set(['http_host' => 'platform.example.test, https://console.example.test:8443/'], 'project');
        Db::execute('CREATE TABLE la_tenant (id INTEGER PRIMARY KEY, sn TEXT, domain_alias TEXT, domain_alias_enable INTEGER, disable INTEGER, delete_time INTEGER)');
        Db::execute('CREATE TABLE la_tenant_domain_alias (id INTEGER PRIMARY KEY, tenant_id INTEGER, domain TEXT, status INTEGER, delete_time INTEGER)');
        Db::name('tenant')->insert(['id' => 42, 'sn' => 'customer', 'domain_alias' => 'legacy.customer.test', 'domain_alias_enable' => 0, 'disable' => 0]);
        Db::name('tenant_domain_alias')->insert(['id' => 1, 'tenant_id' => 42, 'domain' => 'bound.customer.test', 'status' => 1]);
    }

    private function visit(string $host, string $path = '', array $query = [], array $headers = []): Response
    {
        $request = new Request();
        $request->setHost($host)->setPathinfo($path)->setMethod('GET');
        $request->withGet($query)->withHeader($headers)->withServer(['REQUEST_URI' => '/' . $path]);
        app()->instance('request', $request);
        return (new LikeAdminAllowMiddleware())->handle($request, static fn($request) => response([
            'passed' => true, 'tenant_id' => $request->tenantId ?? null,
        ]));
    }

    public function testPlatformRootRedirectsAndExactConfiguredHostsCanOpenTheBackend(): void
    {
        foreach (['platform.example.test', 'CONSOLE.EXAMPLE.TEST:8443'] as $host) {
            $response = $this->visit($host);
            self::assertSame(302, $response->getCode());
            self::assertSame('/platform/', $response->getData());
            foreach (['platform', 'platform/login', 'platform/tenant/tenant', 'platform/index.html', 'platformapi/config/getConfig'] as $path) {
                self::assertTrue($this->visit($host, $path)->getData()['passed']);
            }
        }
    }

    /** @dataProvider blockedHostsAndPaths */
    public function testBoundTenantHostsCannotOpenPlatformPagesOrCallPlatformApis(string $host, string $path): void
    {
        $response = $this->visit($host, $path, ['tenant_id' => 42]);
        self::assertSame(str_starts_with(strtolower($path), 'platformapi') ? 403 : 404, $response->getCode());
        self::assertStringNotContainsString('"passed":true', $response->getContent());
    }

    public static function blockedHostsAndPaths(): array
    {
        $cases = [];
        foreach (['customer.platform.example.test', 'bound.customer.test', 'legacy.customer.test', 'customer.console.example.test'] as $host) {
            foreach (['platform', 'platform/login', 'platform/system-service/license', 'platform/index.html', 'platformapi/login/account', 'Platformapi/config/getConfig'] as $path) {
                $cases[] = [$host, $path];
            }
        }
        return $cases;
    }

    public function testMissingConfigurationStillBlocksTenantBindingsAndAllowsOtherPlatformHosts(): void
    {
        Config::set(['http_host' => ''], 'project');
        self::assertSame(404, $this->visit('bound.customer.test', 'platform')->getCode());
        self::assertTrue($this->visit('unknown.test', 'platformapi/login/account')->getData()['passed']);
        self::assertSame(403, $this->visit('customer.any-root.test', 'platformapi/login/account')->getCode());
    }

    public function testUnboundHostsWorkWithoutBeingListedInPlatformConfiguration(): void
    {
        foreach (['new-console.example.test', 'unknown.test', 'unassigned.platform.example.test', 'platform.example.test.evil.test'] as $host) {
            self::assertSame('/platform/', $this->visit($host)->getData());
            foreach (['platform/login', 'platform/index.html', 'platformapi/config/getConfig'] as $path) {
                self::assertTrue($this->visit($host, $path)->getData()['passed']);
            }
            self::assertSame(42, (int)$this->visit($host, '', ['tenant_id' => 42])->getData()['tenant_id']);
        }
    }

    public function testTenantBindingTakesPriorityOverConfiguredPlatformHost(): void
    {
        Config::set(['http_host' => 'bound.customer.test'], 'project');
        self::assertSame(404, $this->visit('bound.customer.test', 'platform/login')->getCode());
        self::assertSame(403, $this->visit('bound.customer.test', 'platformapi/config/getConfig')->getCode());
        self::assertSame(42, (int)$this->visit('bound.customer.test')->getData()['tenant_id']);
    }

    public function testDisabledTenantAndDisabledAliasDoNotBecomePlatformHosts(): void
    {
        Db::name('tenant')->where('id', 42)->update(['disable' => 1]);
        Db::name('tenant_domain_alias')->insert(['id' => 2, 'tenant_id' => 42, 'domain' => 'disabled.customer.test', 'status' => 0]);
        foreach (['bound.customer.test', 'disabled.customer.test', 'legacy.customer.test', 'customer.platform.example.test'] as $host) {
            self::assertSame(403, $this->visit($host, 'platformapi/config/getConfig')->getCode());
        }
    }

    public function testDisabledAliasRemainsUnavailableToTenantsButReservedFromPlatformAccess(): void
    {
        Db::name('tenant_domain_alias')->insert(['id' => 2, 'tenant_id' => 42, 'domain' => 'disabled.customer.test', 'status' => 0]);
        self::assertTrue(TenantDomainAliasService::findTenantByDomain('disabled.customer.test')->isEmpty());
        self::assertSame(403, $this->visit('disabled.customer.test', 'platformapi/config/getConfig')->getCode());
    }

    public function testDeletedAliasIsNoLongerATenantBinding(): void
    {
        Db::name('tenant_domain_alias')->insert(['id' => 2, 'tenant_id' => 42, 'domain' => 'removed.customer.test', 'status' => 1, 'delete_time' => time()]);
        self::assertTrue($this->visit('removed.customer.test', 'platformapi/config/getConfig')->getData()['passed']);
    }

    public function testMultiplePlatformRootsStillResolveRegisteredTenantSubdomains(): void
    {
        foreach (['customer.platform.example.test', 'customer.console.example.test:8443'] as $host) {
            self::assertSame(42, (int)$this->visit($host, 'tenantapi/config/getConfig')->getData()['tenant_id']);
        }
    }

    public function testForwardedHostCannotTurnTenantRequestsIntoPlatformRequests(): void
    {
        foreach (['platform/login' => 404, 'platformapi/config/getConfig' => 403] as $path => $code) {
            $request = new Request();
            $request->setMethod('GET')->setPathinfo($path)->withServer([
                'HTTP_HOST' => 'bound.customer.test',
                'HTTP_X_FORWARDED_HOST' => 'platform.example.test',
                'REQUEST_URI' => '/' . $path,
            ]);
            app()->instance('request', $request);
            $response = (new LikeAdminAllowMiddleware())->handle($request, static fn() => response('passed'));
            self::assertSame($code, $response->getCode());
        }
    }

    public function testTenantEntriesKeepResolvingOnSubdomainsAliasesAndExplicitIds(): void
    {
        Config::set(['http_host' => 'platform.example.test'], 'project');
        foreach (['customer.platform.example.test', 'bound.customer.test', 'legacy.customer.test'] as $host) {
            self::assertSame(42, (int)$this->visit($host)->getData()['tenant_id']);
            self::assertSame(42, (int)$this->visit($host, 'tenantapi/config/getConfig')->getData()['tenant_id']);
        }
        self::assertSame(42, (int)$this->visit('platform.example.test', '', ['tenant_id' => 42])->getData()['tenant_id']);
        self::assertSame(42, (int)$this->visit('platform.example.test', '', [], ['tenant-id' => '42'])->getData()['tenant_id']);
        self::assertSame(42, (int)$this->visit('platform.example.test', 't/42')->getData()['tenant_id']);
        self::assertSame(42, (int)$this->visit('platform.example.test', 'platformapi/tenant/detail', ['tenant_id' => 42])->getData()['tenant_id']);
    }

    public function testPublicWechatAndPaymentCallbacksStillPassThrough(): void
    {
        foreach (['wechat/open-platform/callback', 'wechat/open-platform/wx123/callback', 'wechat/open-platform/authorize', 'wechat/official-oauth/callback', 'wechat/virtual-pay/42/wx123/callback', 'api/brand.payment/notifyWechat', 'api/brand.domain/probe'] as $path) {
            self::assertTrue($this->visit('platform.example.test', $path)->getData()['passed']);
        }
    }
}
