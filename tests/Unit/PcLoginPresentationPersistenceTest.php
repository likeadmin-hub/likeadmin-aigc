<?php
use app\common\enum\AdminTerminalEnum;
use app\common\service\ConfigService;
use app\common\service\PcLoginPresentationService;
use PHPUnit\Framework\TestCase;
use think\facade\Config;
use think\facade\Db;

/** @runTestsInSeparateProcesses @preserveGlobalState disabled */
final class PcLoginPresentationPersistenceTest extends TestCase
{
    public function testRoundTripTenantIsolationAndOldClientCompatibility(): void
    {
        (new \think\App())->initialize();
        Config::set(['default' => 'login_test', 'connections' => ['login_test' => [
            'type' => 'sqlite', 'database' => ':memory:', 'prefix' => 'la_', 'fields_strict' => true, 'fields_cache' => false,
        ]]], 'database');
        // In-memory cache avoids touching the running tenant's storage cache.
        Config::set(['default' => 'array', 'stores' => ['array' => ['type' => 'file', 'path' => sys_get_temp_dir() . '/login-presentation-' . getmypid() . '/']]], 'cache');
        foreach (['tenant_config' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, type TEXT, name TEXT, value TEXT',
            'config' => 'id INTEGER PRIMARY KEY, type TEXT, name TEXT, value TEXT',
            'tenant' => 'id INTEGER PRIMARY KEY, is_storage INTEGER DEFAULT 0, allow_local_storage INTEGER DEFAULT 0, delete_time INTEGER'] as $table => $columns) {
            Db::execute("CREATE TABLE la_{$table} ({$columns})");
        }
        request()->source = AdminTerminalEnum::TENANT;
        request()->tenantId = 1;
        Db::name('tenant')->insertAll([['id' => 1], ['id' => 2]]);
        $params = ['pc_login_slides' => [['image' => 'https://assets.example.test/one.png', 'title' => '第一张', 'background' => '#DFECFF', 'background_dark' => '#142030']], 'pc_login_caption' => '平台宣传文案'];
        PcLoginPresentationService::save($params);
        self::assertSame($params, PcLoginPresentationService::get());
        PcLoginPresentationService::save(['pc_title' => '旧版客户端保存']);
        self::assertSame($params, PcLoginPresentationService::get());
        request()->tenantId = 2;
        self::assertSame(['pc_login_slides' => [], 'pc_login_caption' => ''], PcLoginPresentationService::get());
        request()->tenantId = 1;
        PcLoginPresentationService::save(['pc_login_slides' => [], 'pc_login_caption' => '']);
        self::assertSame(['pc_login_slides' => [], 'pc_login_caption' => ''], PcLoginPresentationService::get());
    }
}
