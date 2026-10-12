<?php
use app\common\enum\AdminTerminalEnum;
use app\common\service\ConfigService;
use app\common\service\PcHomePresentationService;
use PHPUnit\Framework\TestCase;
use think\facade\Config;
use think\facade\Db;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class PcHomePresentationPersistenceTest extends TestCase
{
    protected function tearDown(): void
    {
        $path = sys_get_temp_dir() . '/home-presentation-' . getmypid() . '/';
        if (!is_dir($path)) return;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }

    public function testRoundTripTenantIsolationAndOldClientCompatibility(): void
    {
        (new \think\App())->initialize();
        Config::set(['default' => 'login_test', 'connections' => ['login_test' => [
            'type' => 'sqlite', 'database' => ':memory:', 'prefix' => 'la_', 'fields_strict' => true, 'fields_cache' => false,
        ]]], 'database');
        // Isolated file cache avoids touching the running tenant's storage cache.
        Config::set(['default' => 'array', 'stores' => ['array' => ['type' => 'file', 'path' => sys_get_temp_dir() . '/home-presentation-' . getmypid() . '/']]], 'cache');
        foreach (['tenant_config' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, type TEXT, name TEXT, value TEXT',
            'config' => 'id INTEGER PRIMARY KEY, type TEXT, name TEXT, value TEXT',
            'tenant' => 'id INTEGER PRIMARY KEY, tactics INTEGER DEFAULT 0, is_storage INTEGER DEFAULT 0, allow_local_storage INTEGER DEFAULT 0, delete_time INTEGER'] as $table => $columns) {
            Db::execute("CREATE TABLE la_{$table} ({$columns})");
        }
        request()->source = AdminTerminalEnum::TENANT;
        request()->tenantId = 1;
        Db::name('tenant')->insertAll([['id' => 1], ['id' => 2]]);
        self::assertSame('composer', PcHomePresentationService::topMode());
        ConfigService::set('pc_home_shortcuts', 'published', ['cards' => [['id' => 'saved-card']]]);
        PcHomePresentationService::save(['pc_home_top_mode' => 'composer']);
        self::assertSame('composer', PcHomePresentationService::topMode());
        PcHomePresentationService::save(['pc_title' => 'Older client']);
        self::assertSame('composer', PcHomePresentationService::topMode());
        request()->tenantId = 2;
        self::assertSame('composer', PcHomePresentationService::topMode());
        request()->tenantId = 1;
        PcHomePresentationService::save(['pc_home_top_mode' => 'shortcuts']);
        self::assertSame('shortcuts', PcHomePresentationService::topMode());
        self::assertSame(['cards' => [['id' => 'saved-card']]], ConfigService::get('pc_home_shortcuts', 'published'));
        try {
            PcHomePresentationService::save(['pc_home_top_mode' => 'invalid']);
            self::fail('Invalid mode must be rejected');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('shortcuts', PcHomePresentationService::topMode());
        }
    }
}
