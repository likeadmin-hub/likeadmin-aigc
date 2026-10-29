<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use app\common\service\power\MarketCatalogReadScope;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

final class ShortDramaConfigCatalogPerformanceTest extends TestCase
{
    protected function setUp(): void
    {
        (new \think\App(dirname(__DIR__, 2) . '/'))->initialize();
        $db = config('database.connections.mysql');
        if ($db['hostname'] !== '127.0.0.1' || !in_array($db['database'], ['x_cn', 'z_cn'], true)) self::markTestSkipped('Local database only');
        $connections = config('database.connections');
        $connections['mysql']['trigger_sql'] = true;
        config(['connections' => $connections], 'database');
        Db::connect('mysql', true);
        Db::startTrans();
    }
    protected function tearDown(): void { Db::rollback(); }
    public function testConfigReusesMarketReadsAndKeepsTheSameConfiguration(): void
    {
        $queries=0;
        Db::listen(static function () use (&$queries): void { $queries++; });
        $expected=MarketCatalogReadScope::run(static fn(): array => AigcShortDramaService::config(1));
        $scoped=$queries; $before=$queries;
        $actual=AigcShortDramaService::config(1);
        $actualCount=$queries-$before;
        self::assertSame($expected,$actual);
        self::assertNull(request()->marketCatalogReadScope);
        self::assertLessThanOrEqual($scoped + 5,$actualCount,'The config request should reuse market catalogue reads');
    }
}
