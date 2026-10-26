<?php
namespace Tests\Feature;

use app\common\service\app\aigc_micro_video\MicroVideoCatalog;
use app\common\service\app\aigc_micro_video\MicroVideoSettings;
use PHPUnit\Framework\TestCase;
use think\facade\Cache;
use think\facade\Db;

final class MicroVideoTenantConfigPerformanceTest extends TestCase
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
        // HTTP middleware sets this; a bare CLI benchmark misses tenant storage reads.
        request()->tenantId = 1;
    }

    protected function tearDown(): void
    {
        request()->tenantId = null;
    }

    private function coldCatalog(): void
    {
        Cache::delete('micro_video_callable_models_v3:1:reference');
        $cache = new \ReflectionProperty(MicroVideoCatalog::class, 'cache');
        $cache->setAccessible(true);
        $cache->setValue(null, []);
    }

    public function testPricingPlaceholderDoesNotReadTenantStorageOrEscapeIntoSelection(): void
    {
        $models = MicroVideoCatalog::models(1, false, 'reference');
        self::assertNotEmpty($models);
        $storageReads = 0;
        $queries = 0;
        $prefix = config('database.connections.mysql.prefix');
        Db::listen(static function (string $sql) use (&$storageReads, &$queries, $prefix): void {
            $queries++;
            foreach (['tenant', 'tenant_config', 'config'] as $table) {
                if (str_contains($sql, '`' . $prefix . $table . '`')) { $storageReads++; break; }
            }
        });
        foreach ($models as $model) {
            foreach (['specs', 'start_end_specs'] as $kind) {
                if (empty($model[$kind])) continue;
                $quote = MicroVideoCatalog::quote(1, $model[$kind][0], $model['id']);
                self::assertGreaterThan(0, $quote['user_charge_points']);
                self::assertArrayNotHasKey('reference_assets', $quote['selection']);
                self::assertArrayNotHasKey('reference_images', $quote['selection']);
                self::assertStringNotContainsString('pricing-only', json_encode($quote));
            }
        }
        self::assertGreaterThan(0, $queries);
        self::assertSame(0, $storageReads, 'Synthetic quote images must not repeatedly resolve tenant storage');
    }

    public function testTenantConfigLoadsAndSavesWithoutWarmCatalog(): void
    {
        $this->coldCatalog();
        $detail = MicroVideoSettings::detail(1, true);
        self::assertNotEmpty($detail['available_models']);
        self::assertNull(request()->marketCatalogReadScope);
        $this->coldCatalog();
        $queries = 0;
        Db::listen(static function () use (&$queries): void { $queries++; });
        Db::startTrans();
        try {
            // Lock and read the current config inside the rollback-only transaction.
            // No user form or later settings are overwritten by this verification.
            $row = Db::name('aigc_micro_video_config')->where('tenant_id', 1)->lock(true)->find();
            self::assertNotEmpty($row);
            $config = MicroVideoSettings::get(1);
            MicroVideoSettings::save(1, $config);
            self::assertSame($config, MicroVideoSettings::get(1));
            self::assertNull(request()->marketCatalogReadScope);
            self::assertGreaterThan(0, $queries);
            self::assertLessThan(1500, $queries, 'Cold save must not perform storage queries for every specification');
        } finally {
            Db::rollback();
        }
    }
}
