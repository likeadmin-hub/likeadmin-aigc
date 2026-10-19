<?php

namespace Tests\Feature;

use app\tenantapi\validate\setting\WebSettingValidate;
use PHPUnit\Framework\TestCase;

class TutorialSettingContractTest extends TestCase
{
    public function testTutorialMenuTargetsItsDedicatedPageAcrossEveryBootstrapSurface(): void
    {
        $root = dirname(__DIR__, 2);
        $logic = (string)file_get_contents($root . '/app/tenantapi/logic/auth/MenuLogic.php');
        $method = new \ReflectionMethod(\app\tenantapi\logic\auth\MenuLogic::class, 'ensureTutorialMenu');
        $methodSource = implode('', array_slice(file($method->getFileName()), $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
        self::assertStringContainsString("'component' => 'setting/website/tutorial'", $methodSource);
        self::assertStringNotContainsString("'component' => 'setting/website/information'", $methodSource);
        foreach (['public/install/db/like.sql', 'app/platformapi/db/tenantData.sql'] as $file) {
            $sql = (string)file_get_contents($root . '/' . $file);
            self::assertMatchesRegularExpression("~getTutorial','tutorial','setting/website/tutorial'~", $sql, $file);
            self::assertDoesNotMatchRegularExpression("~getTutorial','tutorial','setting/website/information'~", $sql, $file);
        }
        self::assertFileExists($root . '/upgrade/20261019_tutorial_route.sql');
        $migration = (string)file_get_contents($root . '/upgrade/20261019_tutorial_route.sql');
        self::assertSame($migration, file_get_contents($root . '/public/upgrade/20261019_tutorial_route.sql'));
        self::assertStringContainsString("`component` = 'setting/website/tutorial'", $migration);
        // Menu refresh invokes repair for existing tenants as well as new installs.
        self::assertStringContainsString('self::ensureTutorialMenu($tenantId);', $logic);
    }

    public function testPublishedTutorialMigrationRemainsImmutable(): void
    {
        // Published migration bytes are part of the update execution history.
        // Route repair must use a new migration rather than rerunning old setup.
        $root = dirname(__DIR__, 2);
        foreach (['upgrade/20260815_platform_tutorial.sql', 'public/upgrade/20260815_platform_tutorial.sql'] as $file) {
            self::assertSame('0c62ac7ef9c60af9e1585bbf66d177fd94c3625a34b86015aa128d36c96850de', hash_file('sha256', $root . '/' . $file), $file);
        }
    }

    public function testTutorialRouteMigrationCanRunTwiceAndPreservesTenantOwnedMenus(): void
    {
        $db = new \PDO('sqlite::memory:');
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE la_tenant_system_menu (id INTEGER PRIMARY KEY, tenant_id INTEGER, component TEXT, source TEXT, source_menu_key TEXT, name TEXT, update_time INTEGER)');
        $insert = $db->prepare('INSERT INTO la_tenant_system_menu VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach ([
            [1, 8, 'setting/website/information', 'core', 'core_tenant_tutorial', '新手教程', 100],
            [2, 8, 'custom/tutorial', 'tenant', 'core_tenant_tutorial', '租户自定义教程', 200],
            [3, 8, 'setting/website/information', 'core', 'core_tenant_website', '网站设置', 300],
            [4, 8, 'setting/website/tutorial', 'core', 'core_tenant_tutorial', '已修复教程', 400],
            [5, 9, 'setting/website/information', 'core', 'core_tenant_tutorial', '另一租户教程', 500],
        ] as $row) $insert->execute($row);
        $before = $db->query('SELECT * FROM la_tenant_system_menu ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
        $migrationPath = dirname(__DIR__, 2) . '/upgrade/20261019_tutorial_route.sql';
        self::assertFileExists($migrationPath);
        $sql = (string)file_get_contents($migrationPath);
        self::assertSame(2, $db->exec($sql));
        $after = $db->query('SELECT * FROM la_tenant_system_menu ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
        $expected = $before;
        $expected[0]['component'] = $expected[4]['component'] = 'setting/website/tutorial';
        self::assertSame($expected, $after);
        self::assertSame(0, $db->exec($sql));
        self::assertSame($after, $db->query('SELECT * FROM la_tenant_system_menu ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function testTutorialRuntimeRepairPreservesTenantOwnedMenus(): void
    {
        (new \think\App())->initialize();
        \think\facade\Config::set(['default' => 'tutorial_test', 'connections' => ['tutorial_test' => [
            'type' => 'sqlite', 'database' => ':memory:', 'prefix' => 'la_', 'fields_strict' => true, 'fields_cache' => false,
        ]]], 'database');
        $db = \think\facade\Db::class;
        $db::execute('CREATE TABLE la_tutorial_menu_test (id INTEGER PRIMARY KEY, tenant_id INTEGER, pid INTEGER, type TEXT, name TEXT, icon TEXT, sort INTEGER, perms TEXT, paths TEXT, component TEXT, selected TEXT, params TEXT, is_cache INTEGER, is_show INTEGER, is_disable INTEGER, app_code TEXT, source TEXT, source_menu_key TEXT, is_core INTEGER, create_time INTEGER, update_time INTEGER)');
        $db::name('tutorial_menu_test')->insertAll([
            ['id' => 9410, 'tenant_id' => 8, 'name' => '租户自定义教程', 'component' => 'custom/tutorial', 'source' => 'tenant', 'source_menu_key' => 'core_tenant_tutorial'],
            ['id' => 9500, 'tenant_id' => 8, 'name' => '新手教程', 'component' => 'setting/website/information', 'source' => 'core', 'source_menu_key' => 'core_tenant_tutorial'],
        ]);
        $before = $db::name('tutorial_menu_test')->where('id', 9410)->find();
        $method = new \ReflectionMethod(\app\tenantapi\logic\auth\MenuLogic::class, 'upsertSystemMenu');
        $method->setAccessible(true);
        $id = $method->invoke(null, 'tutorial_menu_test', 8, [
            'pid' => 28, 'name' => '新手教程', 'paths' => 'tutorial', 'component' => 'setting/website/tutorial',
            'perms' => 'setting.web.web_setting/getTutorial', 'source_menu_key' => 'core_tenant_tutorial',
        ], 9410);
        self::assertSame(9500, $id);
        self::assertSame('setting/website/tutorial', $db::name('tutorial_menu_test')->where('id', 9500)->value('component'));
        self::assertSame($before, $db::name('tutorial_menu_test')->where('id', 9410)->find());
        self::assertSame(2, $db::name('tutorial_menu_test')->count());
    }

    public function testTutorialUrlValidationOnlyAllowsHttpSchemes(): void
    {
        $validator = new WebSettingValidate();

        self::assertTrue($validator->checkTutorialUrl('https://example.test/tutorial'));
        self::assertTrue($validator->checkTutorialUrl('http://example.test/tutorial'));
        self::assertNotTrue($validator->checkTutorialUrl('javascript:alert(1)'));
        self::assertNotTrue($validator->checkTutorialUrl('not-a-url'));
    }

    public function testPublicConfigAndTenantMenuExposeTheTutorialContract(): void
    {
        $root = dirname(__DIR__, 2);
        $pcLogic = (string)file_get_contents($root . '/app/api/logic/PcLogic.php');
        $menuLogic = (string)file_get_contents($root . '/app/tenantapi/logic/auth/MenuLogic.php');
        $controller = (string)file_get_contents($root . '/app/tenantapi/controller/setting/web/WebSettingController.php');
        $logic = (string)file_get_contents($root . '/app/tenantapi/logic/setting/web/WebSettingLogic.php');
        $service = (string)file_get_contents($root . '/app/common/service/TutorialConfigService.php');

        self::assertStringContainsString("'tutorial' => \$tutorial", $pcLogic);
        self::assertStringContainsString('TutorialConfigService::get()', $pcLogic);
        self::assertStringContainsString('ConfigService::get(self::TYPE', $service);
        self::assertStringContainsString('ConfigService::set(self::TYPE', $service);
        self::assertStringContainsString('core_tenant_tutorial', $menuLogic);
        self::assertStringContainsString('systemSettingMenuId', $menuLogic);
        self::assertStringContainsString("'paths' => 'tutorial'", $menuLogic);
        self::assertStringContainsString('setting.web.web_setting/getTutorial', $menuLogic);
        self::assertStringContainsString('setting.web.web_setting/setTutorial', $menuLogic);
        self::assertStringContainsString('getTutorial', $controller);
        self::assertStringContainsString('setTutorial', $controller);
        self::assertStringContainsString('TutorialConfigService::get()', $logic);
        self::assertStringContainsString('TutorialConfigService::save($params)', $logic);
    }

    public function testPlatformMenuNoLongerExposesTutorialConfiguration(): void
    {
        $root = dirname(__DIR__, 2);
        $menuLogic = (string)file_get_contents($root . '/app/platformapi/logic/auth/MenuLogic.php');
        $installSql = (string)file_get_contents($root . '/public/install/db/like.sql');
        $upgradeSql = (string)file_get_contents($root . '/upgrade/20260815_platform_tutorial.sql');
        $publicUpgradeSql = (string)file_get_contents($root . '/public/upgrade/20260815_platform_tutorial.sql');
        $tenantDataSql = (string)file_get_contents($root . '/app/platformapi/db/tenantData.sql');

        self::assertStringContainsString('removeTutorialMenu', $menuLogic);
        self::assertStringNotContainsString('ensureTutorialMenu', $menuLogic);
        self::assertStringNotContainsString('core_platform_tutorial', $installSql);
        self::assertSame($upgradeSql, $publicUpgradeSql);
        self::assertStringContainsString('DELETE FROM `la_system_menu`', $upgradeSql);
        self::assertStringContainsString('core_tenant_tutorial', $upgradeSql);
        self::assertStringContainsString('core_tenant_tutorial', $tenantDataSql);
    }

    public function testCompiledTenantEntryKeepsWebsiteInformationAndTutorialPagesSeparate(): void
    {
        $root = dirname(__DIR__, 2);
        $entry = \Tests\Support\CompiledAssets::vitePage('admin', 'setting/website/information');
        $tutorial = \Tests\Support\CompiledAssets::vitePage('admin', 'setting/website/tutorial');
        $websiteApi = \Tests\Support\CompiledAssets::read('admin', 'website', 'js');

        self::assertStringNotContainsString('显示教程入口', $entry);
        self::assertStringContainsString('显示教程入口', $tutorial);
        self::assertStringContainsString('/setting.web.web_setting/getTutorial', $websiteApi);
        self::assertStringContainsString('/setting.web.web_setting/setTutorial', $websiteApi);
        self::assertStringContainsString("['http:', 'https:'].includes(url.protocol)", \Tests\Support\CompiledAssets::source('tenant/src/views/setting/website/tutorial.vue'));

    }
}
