<?php
namespace Tests\Feature;

use app\common\service\app\aigc_llm\agent\AgentComposerSettings;
use app\common\service\power\MarketCatalogReadScope;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

final class AgentComposerCatalogPerformanceTest extends TestCase
{
    protected function setUp(): void
    {
        (new \think\App(dirname(__DIR__, 2) . '/'))->initialize();
        $db = config('database.connections.mysql');
        if ($db['hostname'] !== '127.0.0.1' || !in_array($db['database'], ['x_cn', 'z_cn'], true)) {
            self::markTestSkipped('Local read-only verification only');
        }
        $connections = config('database.connections');
        $connections['mysql']['trigger_sql'] = true;
        config(['connections' => $connections], 'database');
        Db::connect('mysql', true);
    }

    public function testAgentOptionsReuseCatalogWithoutChangingResultsOrLeakingScope(): void
    {
        $queries = 0;
        Db::listen(static function () use (&$queries): void { $queries++; });
        $expected = MarketCatalogReadScope::run(static fn(): array => AgentComposerSettings::textOptions(1));
        $scopedQueries = $queries;
        self::assertNotEmpty($expected['models']);
        $before = $queries;
        $actual = AgentComposerSettings::textOptions(1);
        $actualQueries = $queries - $before;
        self::assertSame($expected, $actual);
        self::assertNull(request()->marketCatalogReadScope);
        self::assertLessThanOrEqual($scopedQueries + 4, $actualQueries, 'Agent model validation must not reload the entire text catalogue for each model');
    }
}
