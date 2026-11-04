<?php

use app\common\enum\AdminTerminalEnum;
use app\common\service\tenant\TenantPackageService;
use app\platformapi\controller\tenant\PackageController;
use PHPUnit\Framework\TestCase;
use think\facade\Config;
use think\facade\Db;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class TenantPackageCatalogTest extends TestCase
{
    protected function setUp(): void
    {
        (new \think\App())->initialize();
        Config::set(['default' => 'catalog_test', 'connections' => ['catalog_test' => [
            'type' => 'sqlite', 'database' => ':memory:', 'prefix' => 'la_', 'fields_strict' => true, 'fields_cache' => false,
        ]]], 'database');
        request()->source = AdminTerminalEnum::PLATFORM;
        Db::execute('CREATE TABLE la_app (id INTEGER PRIMARY KEY, code TEXT, name TEXT, status TEXT, sort INTEGER DEFAULT 0, icon TEXT, current_version TEXT DEFAULT "", manifest_json TEXT)');
        Db::execute('CREATE TABLE la_app_plan (id INTEGER PRIMARY KEY, app_code TEXT, name TEXT, status INTEGER, duration_months INTEGER, sort INTEGER DEFAULT 0)');
        Db::execute('CREATE TABLE la_app_migration (id INTEGER PRIMARY KEY, scope TEXT, app_code TEXT, tenant_id INTEGER, migration_key TEXT, status TEXT, error TEXT)');
        foreach ([
            [1, 'aigc_image', 'installed'], [2, 'system_default', 'installed'],
            [3, 'aigc_geo', 'not_installed'], [4, 'custom_disabled', 'disabled'],
            [5, 'custom_empty', 'installed'], [6, 'custom_removed', 'removed'],
            [7, 'aigc_pic_lipsync', 'installed'], [8, 'open_platform', 'installed'], [9, 'aigc_copywriting', 'installed'],
        ] as [$id, $code, $status]) {
            Db::name('app')->insert(['id' => $id, 'code' => $code, 'name' => $code, 'status' => $status, 'icon' => '']);
        }
        foreach ([
            [1, 'aigc_image', 1], [2, 'aigc_image', 1], [3, 'aigc_image', 0],
            [4, 'aigc_geo', 1], [5, 'custom_disabled', 1], [6, 'custom_removed', 1],
            [7, 'unknown_app', 1], [8, 'custom_empty', 0],
        ] as [$id, $code, $status]) {
            Db::name('app_plan')->insert(['id' => $id, 'app_code' => $code, 'name' => 'Plan ' . $id, 'status' => $status, 'duration_months' => 1]);
        }
    }

    public function testCatalogExplainsEligibilityAndDoesNotWrite(): void
    {
        $before = $this->snapshot();
        $options = TenantPackageService::appPlanOptions();
        self::assertSame([1, 2], array_map('intval', array_column($options, 'plan_id')));
        $catalog = TenantPackageService::appPlanCatalog();
        self::assertSame($options, $catalog['options']);
        $apps = array_column($catalog['apps'], null, 'app_code');
        self::assertTrue($apps['aigc_image']['selectable']);
        self::assertSame(2, $apps['aigc_image']['selectable_plan_count']);
        foreach (['system_default', 'aigc_pic_lipsync', 'open_platform', 'aigc_copywriting'] as $code) {
            self::assertSame('default_no_plan', $apps[$code]['unavailable_reason_code']);
            self::assertSame('默认开通，无需绑定', $apps[$code]['unavailable_reason']);
            self::assertFalse($apps[$code]['selectable']);
        }
        self::assertSame('not_installed', $apps['aigc_geo']['unavailable_reason_code']);
        self::assertSame(1, $apps['aigc_geo']['enabled_plan_count']);
        self::assertSame('disabled', $apps['custom_disabled']['unavailable_reason_code']);
        self::assertSame('removed', $apps['custom_removed']['unavailable_reason_code']);
        self::assertSame('no_enabled_plan', $apps['custom_empty']['unavailable_reason_code']);
        self::assertSame(1, $catalog['counts']['selectable_apps']);
        self::assertSame(2, $catalog['counts']['selectable_plans']);
        self::assertSame(6, $catalog['counts']['installed_apps']);
        self::assertSame(count($catalog['apps']), $catalog['counts']['local_apps']);
        self::assertSame(count($catalog['apps']) - 1, $catalog['counts']['excluded_apps']);
        self::assertArrayHasKey('aigc_video', $apps, 'Unregistered bundled manifests remain in the local catalog');
        self::assertSame($before, $this->snapshot(), 'Catalog reads must not install apps, create plans, or change rights');
    }

    public function testControllerKeepsLegacyArrayAndUsesExplicitOptIn(): void
    {
        $controller = new PackageController(app());
        foreach ([[], ['catalog' => 0], ['catalog' => 2]] as $query) {
            request()->withGet($query);
            self::assertSame(TenantPackageService::appPlanOptions(), $controller->appPlans()->getData()['data']);
        }
        request()->withGet(['catalog' => 1]);
        $data = $controller->appPlans()->getData()['data'];
        self::assertSame(TenantPackageService::appPlanOptions(), $data['options']);
        self::assertArrayHasKey('apps', $data);
        self::assertArrayHasKey('counts', $data);
    }

    public function testCatalogDoesNotRedefineHistoricalSaveValidation(): void
    {
        $normalize = new ReflectionMethod(TenantPackageService::class, 'normalizeAppPlans');
        $normalize->setAccessible(true);
        // Existing bindings validate the enabled plan; catalog visibility does not rewrite that policy.
        self::assertSame([['app_code' => 'aigc_geo', 'app_plan_id' => 4]], $normalize->invoke(null, [['app_code' => 'aigc_geo', 'app_plan_id' => 4]]));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('绑定的应用套餐不存在或已禁用');
        $normalize->invoke(null, [['app_code' => 'aigc_image', 'app_plan_id' => 3]]);
    }

    public function testBundledCatalogCountsKeepApplicationsSeparateFromPlans(): void
    {
        Db::name('app')->delete(true);
        Db::name('app_plan')->delete(true);
        $noPlans = ['system_default', 'aigc_pic_lipsync', 'open_platform', 'aigc_copywriting'];
        $manifests = glob(base_path() . 'apps/*/manifest.json');
        foreach ($manifests as $index => $file) {
            $manifest = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            $code = $manifest['code'];
            Db::name('app')->insert(['id' => $index + 1, 'code' => $code, 'name' => $manifest['name'], 'status' => $code === 'aigc_geo' ? 'not_installed' : 'installed', 'icon' => '']);
            if (!in_array($code, $noPlans, true)) {
                Db::name('app_plan')->insert(['id' => $index + 1, 'app_code' => $code, 'name' => 'Fixture plan', 'status' => 1, 'duration_months' => 1]);
            }
        }
        // The system app is registered without a bundled manifest.
        Db::name('app')->insert(['id' => count($manifests) + 1, 'code' => 'system_default', 'name' => '系统应用', 'status' => 'installed', 'icon' => '']);
        $before = $this->snapshot();
        $catalog = TenantPackageService::appPlanCatalog();
        self::assertSame([
            'local_apps' => count($manifests) + 1, 'installed_apps' => count($manifests),
            'selectable_apps' => count($manifests) - 4, 'selectable_plans' => count($manifests) - 4, 'excluded_apps' => 5,
        ], $catalog['counts']);
        self::assertSame($before, $this->snapshot());
        fwrite(STDOUT, "\nBundled fixture counts: " . json_encode($catalog['counts']) . "\n");
    }

    private function snapshot(): array
    {
        return array_map(static fn($table) => Db::name($table)->order('id')->select()->toArray(), ['app', 'app_plan', 'app_migration']);
    }
}
