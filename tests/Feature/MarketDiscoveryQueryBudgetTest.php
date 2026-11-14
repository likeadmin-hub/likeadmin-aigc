<?php
namespace Tests\Feature;

use app\common\service\power\{MarketGenerationCatalogService, TenantPowerMarketService};
use PHPUnit\Framework\TestCase;
use think\facade\Db;

final class MarketDiscoveryQueryBudgetTest extends TestCase
{
    /** @dataProvider catalogues */
    public function testDiscoveryReadsEachOwnersRulesOnceAndReleasesSnapshot(string $catalogue): void
    {
        (new \think\App(dirname(__DIR__, 2) . '/'))->initialize();
        $db = config('database.connections.mysql');
        if ($db['hostname'] !== '127.0.0.1' || !in_array($db['database'], ['x_cn', 'z_cn'], true)) {
            self::markTestSkipped('Local read-only database verification only');
        }
        $connections = config('database.connections');
        $connections['mysql']['trigger_sql'] = true;
        config(['connections' => $connections], 'database');
        Db::connect('mysql', true);
        $ruleReads = 0;
        Db::listen(static function ($sql) use (&$ruleReads): void {
            if (str_starts_with($sql, 'SELECT') && str_contains($sql, '`la_power_market_price_rule`')) $ruleReads++;
        });
        $result = match ($catalogue) {
            'market_models' => TenantPowerMarketService::models(1),
            'market_apps' => TenantPowerMarketService::apps(1),
            default => MarketGenerationCatalogService::options(1, $catalogue),
        };
        self::assertNotEmpty($result);
        self::assertLessThanOrEqual(2, $ruleReads, 'Owner rules must not be queried once per SKU');
        self::assertNull(request()->marketCatalogReadScope, 'Discovery must release its snapshot before admission');
    }

    public function catalogues(): array
    {
        return array_map(static fn($type) => [$type], ['market_models', 'market_apps', 'text', 'image', 'video']);
    }
}
