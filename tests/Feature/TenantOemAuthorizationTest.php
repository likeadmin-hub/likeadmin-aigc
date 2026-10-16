<?php

namespace tests\Feature;

use app\common\service\brand\BrandCatalogService;
use app\common\service\brand\TenantBrandService;
use app\platformapi\controller\brand\OrderController;
use PHPUnit\Framework\TestCase;
use think\facade\Config;
use think\facade\Db;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class TenantOemAuthorizationTest extends TestCase
{
    protected function setUp(): void
    {
        (new \think\App())->initialize();
        Config::set(['default' => 'oem_authorization', 'connections' => ['oem_authorization' => [
            'type' => 'sqlite', 'database' => ':memory:', 'prefix' => 'la_',
            'fields_strict' => true, 'fields_cache' => false,
        ]]], 'database');
        Db::execute('CREATE TABLE la_tenant (id INTEGER PRIMARY KEY, disable INTEGER DEFAULT 0, oem_sales_enabled INTEGER DEFAULT 0, oem_flow_enabled INTEGER DEFAULT 0, update_time INTEGER, delete_time INTEGER)');
        Db::name('tenant')->insertAll([['id' => 42], ['id' => 43]]);
    }

    private function save(int $id, int $enabled, int $entry): array
    {
        request()->withPost(['tenant_id' => $id, 'enabled' => $enabled, 'entry_enabled' => $entry]);
        return (new OrderController(app()))->saveSeller()->getData();
    }

    public function testToggleReturnsPersistedStateAndKeepsTenantsIsolated(): void
    {
        $response = $this->save(42, 1, 1);
        self::assertSame(1, $response['code']);
        self::assertSame(['tenant_id' => 42, 'oem_sales_enabled' => 1, 'oem_flow_enabled' => 1], $response['data']);
        BrandCatalogService::assertSeller(42);
        self::assertSame(0, (int)Db::name('tenant')->where('id', 43)->value('oem_sales_enabled'));

        $response = $this->save(42, 0, 0);
        self::assertSame(2, $response['data']['oem_flow_enabled']);
        self::assertTrue(BrandCatalogService::flowEnabled(42), 'Paused V2 must not fall back to legacy');
        self::assertSame(0, (int)Db::name('tenant')->where('id', 42)->value('oem_sales_enabled'));
        try {
            TenantBrandService::createQuotaOrder(42, 1, 1, 999, 1);
            self::fail('Unauthorized tenant created a procurement order');
        } catch (\app\common\service\brand\BrandUserException $e) {
            self::assertStringContainsString('暂未开放', $e->getMessage());
        }
        self::assertSame(1, $this->save(42, 1, 1)['data']['oem_flow_enabled']);
    }

    public function testPauseAndLegacyStatesArePreservedByExistingSellerSettings(): void
    {
        self::assertSame(0, $this->save(42, 1, 0)['data']['oem_flow_enabled']);
        self::assertFalse(BrandCatalogService::flowEnabled(42));
        $this->save(42, 1, 1);
        self::assertSame(2, $this->save(42, 1, 0)['data']['oem_flow_enabled']);
    }

    public function testUnknownAndDeletedTenantsCannotBeAuthorized(): void
    {
        self::assertSame(0, $this->save(999, 1, 1)['code']);
        Db::name('tenant')->where('id', 42)->update(['delete_time' => time()]);
        self::assertSame(0, $this->save(42, 1, 1)['code']);
        self::assertSame(0, (int)Db::name('tenant')->where('id', 42)->value('oem_sales_enabled'));
    }
}
