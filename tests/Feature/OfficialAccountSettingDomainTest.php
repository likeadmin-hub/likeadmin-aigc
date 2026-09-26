<?php

use app\common\enum\AdminTerminalEnum;
use app\tenantapi\logic\channel\OfficialAccountSettingLogic;
use PHPUnit\Framework\TestCase;
use think\facade\Config;
use think\facade\Db;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class OfficialAccountSettingDomainTest extends TestCase
{
    /** @dataProvider hosts */
    public function testConfigurationUsesTheTenantRequestHost(array $server, string $expected): void
    {
        (new \think\App())->initialize();
        Config::set([
            'default' => 'domain_test',
            'connections' => ['domain_test' => [
                'type' => 'sqlite', 'database' => ':memory:', 'prefix' => 'la_',
            ]],
        ], 'database');
        Db::execute('CREATE TABLE la_tenant (id INTEGER PRIMARY KEY, tactics INTEGER DEFAULT 0, delete_time INTEGER)');
        Db::execute('CREATE TABLE la_tenant_config (id INTEGER PRIMARY KEY, tenant_id INTEGER, type TEXT, name TEXT, value TEXT)');
        Db::name('tenant')->insert(['id' => 1]);
        $_SERVER['SERVER_NAME'] = 'opc.example.com';
        request()->tenantId = 1;
        request()->source = AdminTerminalEnum::TENANT;
        request()->setHost('')->withServer(array_merge($server, [
            'SERVER_NAME' => 'opc.example.com', 'REQUEST_URI' => '/tenantapi/channel.official_account_setting/getConfig?tenant_id=1',
        ]));

        $config = (new OfficialAccountSettingLogic())->getConfig();
        foreach (['business_domain', 'js_secure_domain', 'web_auth_domain'] as $key) {
            self::assertSame($expected, $config[$key]);
        }
        self::assertSame($expected, parse_url($config['url'], PHP_URL_HOST));
    }

    public function hosts(): array
    {
        return [
            'tenant domain differs from nginx server name' => [['HTTP_HOST' => 'aigc.example.com'], 'aigc.example.com'],
            'domain has no scheme path or port' => [['HTTP_HOST' => 'tenant.example.com:8443'], 'tenant.example.com'],
            'trusted proxy forwards tenant host' => [['HTTP_HOST' => 'internal.example.com', 'HTTP_X_FORWARDED_HOST' => 'tenant.example.com'], 'tenant.example.com'],
        ];
    }
}
