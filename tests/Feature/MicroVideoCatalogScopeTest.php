<?php
namespace Tests\Feature;

use app\common\service\app\aigc_micro_video\MicroVideoCatalog;
use app\common\service\power\MarketCatalogReadScope;
use app\common\service\power\MarketVideoModelRuntimeService;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

final class MicroVideoCatalogScopeTest extends TestCase
{
    protected function setUp(): void
    {
        (new \think\App(dirname(__DIR__, 2) . '/'))->initialize();
        $db = config('database.connections.mysql');
        if ($db['hostname'] !== '127.0.0.1' || !in_array($db['database'], ['x_cn', 'z_cn'], true)) {
            self::markTestSkipped('Local read/rollback verification only');
        }
        $connections = config('database.connections');
        $connections['mysql']['trigger_sql'] = true;
        config(['connections' => $connections], 'database');
        Db::connect('mysql', true);
    }

    private function selection(): array
    {
        $models = array_values(array_filter(MicroVideoCatalog::models(1, false, 'reference'), static fn(array $model): bool => str_starts_with($model['id'], 'market_video_model:')));
        self::assertNotEmpty($models);
        self::assertNull(request()->marketCatalogReadScope, 'Catalog must release its snapshot');
        return MicroVideoCatalog::quote(1, $models[0]['specs'][0], $models[0]['id'])['selection'] + [
            'reference_images' => ['uploads/aigc_micro_video/pricing-only.png'],
            'reference_assets' => [['type' => 'image', 'uri' => 'uploads/aigc_micro_video/pricing-only.png', 'role' => 'reference_image']],
        ];
    }

    public function testDerivedContractChangesAreNeverReusedAndDoNotOutliveScope(): void
    {
        $reads = 0;
        $read = static function () use (&$reads): array { return [++$reads]; };
        MarketCatalogReadScope::run(static function () use ($read): array {
            self::assertSame([1], MarketCatalogReadScope::derive('fixture', ['required' => false], $read));
            self::assertSame([1], MarketCatalogReadScope::derive('fixture', ['required' => false], $read));
            self::assertSame([2], MarketCatalogReadScope::derive('fixture', ['required' => true], $read));
            self::assertSame([3], MarketCatalogReadScope::derive('fixture', ['required' => 1], $read));
            return [];
        });
        self::assertSame([4], MarketCatalogReadScope::derive('fixture', ['required' => 1], $read));
    }

    public function testRepeatedDisplayQuotesReuseReadsAndPreserveLiveResult(): void
    {
        $selection = $this->selection();
        $live = MarketVideoModelRuntimeService::quote(1, $selection);
        $queries = 0;
        Db::listen(static function () use (&$queries): void { $queries++; });
        MarketCatalogReadScope::run(function () use ($selection, $live, &$queries): array {
            self::assertSame($live, MarketVideoModelRuntimeService::quote(1, $selection));
            self::assertGreaterThan(0, $queries, 'Query instrumentation must be active');
            $before = $queries;
            self::assertSame($live, MarketVideoModelRuntimeService::quote(1, $selection));
            self::assertSame($before, $queries, 'Repeated ratios/durations must reuse raw market reads');
            $withoutSku = $selection;
            unset($withoutSku['market_sku_id'], $withoutSku['sku_id']);
            $fallback = MarketVideoModelRuntimeService::quote(1, $withoutSku);
            $before = $queries;
            self::assertSame($fallback, MarketVideoModelRuntimeService::quote(1, $withoutSku));
            self::assertSame($before, $queries, 'Product fallback must also reuse its SKU list');
            return [];
        });
        self::assertNull(request()->marketCatalogReadScope);
    }

    public function testNextQuoteSeesPlatformDelisting(): void
    {
        $selection = $this->selection();
        MarketCatalogReadScope::run(static fn(): array => MarketVideoModelRuntimeService::quote(1, $selection));
        Db::startTrans();
        try {
            Db::name('power_market_sku')->where('id', $selection['market_sku_id'])->update(['sale_status' => 0]);
            $this->expectExceptionMessage('所选视频规格已下架');
            MarketVideoModelRuntimeService::quote(1, $selection);
        } finally {
            Db::rollback();
        }
    }

    public function testTenantAvailabilityIsIsolatedAndFailedScopeIsReleased(): void
    {
        $selection = $this->selection();
        Db::startTrans();
        try {
            $where = ['tenant_id' => 2, 'sku_id' => $selection['market_sku_id']];
            $row = Db::name('tenant_power_market_sku_price')->where($where)->find();
            if ($row) Db::name('tenant_power_market_sku_price')->where($where)->update(['sale_status' => 0]);
            else Db::name('tenant_power_market_sku_price')->insert($where + ['sale_status' => 0, 'sale_points' => 0]);
            try {
                MarketCatalogReadScope::run(static function () use ($selection): array {
                    MarketVideoModelRuntimeService::quote(1, $selection);
                    return MarketVideoModelRuntimeService::quote(2, $selection);
                });
                self::fail('Tenant 2 must not reuse tenant 1 availability');
            } catch (\Exception $e) {
                self::assertSame('租户未上架该视频规格', $e->getMessage());
            }
            self::assertNull(request()->marketCatalogReadScope, 'Failed discovery must release the snapshot');
            self::assertGreaterThan(0, MarketVideoModelRuntimeService::quote(1, $selection)['user_charge_points']);
        } finally {
            Db::rollback();
        }
    }
}
