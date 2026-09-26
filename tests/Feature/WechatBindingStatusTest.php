<?php

use app\api\logic\LoginLogic;
use app\api\logic\UserLogic;
use app\common\enum\AdminTerminalEnum;
use app\common\enum\user\UserTerminalEnum;
use app\common\model\user\User;
use PHPUnit\Framework\TestCase;
use think\facade\Config;
use think\facade\Db;

/**
 * Exercise real identity writes and profile reads independently of WeChat network calls.
 * Each process uses only an in-memory database and a private temporary cache.
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class WechatBindingStatusTest extends TestCase
{
    private string $cachePath;

    protected function setUp(): void
    {
        (new \think\App())->initialize();
        Config::set([
            'default' => 'merge_test',
            'connections' => ['merge_test' => [
                'type' => 'sqlite', 'database' => ':memory:', 'prefix' => 'la_',
                'fields_strict' => true, 'fields_cache' => false,
            ]],
        ], 'database');
        $this->cachePath = sys_get_temp_dir() . '/wechat-merge-test-' . bin2hex(random_bytes(8)) . '/';
        Config::set(['default' => 'file', 'stores' => ['file' => [
            'type' => 'File', 'path' => $this->cachePath, 'prefix' => '',
        ]]], 'cache');
        request()->tenantId = 1;
        request()->source = AdminTerminalEnum::USER;

        foreach ([
            'tenant' => 'id INTEGER PRIMARY KEY, sn TEXT, tactics INTEGER DEFAULT 0, delete_time INTEGER',
            'user_membership' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, user_id INTEGER',
            'membership_plan' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, name TEXT, status INTEGER, delete_time INTEGER',
            'distribution_config' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, status INTEGER',
            'tenant_config' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, type TEXT, name TEXT, value TEXT',
            'user' => "id INTEGER PRIMARY KEY, tenant_id INTEGER, sn TEXT, account TEXT, mobile TEXT DEFAULT '', password TEXT DEFAULT '', sex INTEGER DEFAULT 0, real_name TEXT DEFAULT '', nickname TEXT DEFAULT '', avatar TEXT DEFAULT '', is_new_user INTEGER DEFAULT 1, is_disable INTEGER DEFAULT 0, user_money NUMERIC DEFAULT 0, create_time INTEGER, update_time INTEGER, delete_time INTEGER",
            'user_auth' => "id INTEGER PRIMARY KEY, tenant_id INTEGER, user_id INTEGER, appid TEXT DEFAULT '', openid TEXT, unionid TEXT DEFAULT '', terminal INTEGER, create_time INTEGER, update_time INTEGER",
            'user_session' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, user_id INTEGER, terminal INTEGER, token TEXT, expire_time INTEGER, create_time INTEGER, update_time INTEGER',
            'tenant_sms_log' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, mobile TEXT, code TEXT, scene_id INTEGER, send_status INTEGER, send_time INTEGER, is_verify INTEGER DEFAULT 0, check_num INTEGER DEFAULT 0, create_time INTEGER, update_time INTEGER, delete_time INTEGER',
        ] as $table => $columns) {
            Db::execute("CREATE TABLE la_{$table} ({$columns})");
        }
        Db::name('tenant')->insertAll([
            ['id' => 1, 'sn' => 'first'], ['id' => 2, 'sn' => 'second'],
        ]);
        Db::name('user')->insertAll([
            ['id' => 10, 'tenant_id' => 1, 'sn' => '12345678', 'account' => 'u12345678', 'mobile' => '', 'user_money' => 0],
            ['id' => 20, 'tenant_id' => 1, 'sn' => '87654321', 'account' => 'phone-owner', 'mobile' => '13800000001', 'user_money' => 99],
        ]);
        Db::name('user_auth')->insert([
            'id' => 1, 'tenant_id' => 1, 'user_id' => 10,
            'openid' => 'test-mini-openid', 'unionid' => 'test-unionid', 'terminal' => UserTerminalEnum::WECHAT_MMP,
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->cachePath) && is_dir($this->cachePath)) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->cachePath, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->cachePath);
        }
    }

    public function testBindingIsVisibleInBothProfileEndpointsAndUnbindUpdatesImmediately(): void
    {
        $actor = ['user_id' => 20, 'tenant_id' => 1, 'terminal' => UserTerminalEnum::WECHAT_OA];
        self::assertSame(0, UserLogic::center($actor)['is_auth']);
        self::assertFalse(UserLogic::info(20)['has_oa_auth']);
        self::assertTrue(LoginLogic::createAuth(['user_id' => 20, 'openid' => 'oa-test', 'terminal' => UserTerminalEnum::WECHAT_OA]));
        self::assertTrue(LoginLogic::createAuth(['user_id' => 20, 'openid' => 'oa-test', 'terminal' => UserTerminalEnum::WECHAT_OA]));
        self::assertSame(1, UserLogic::center($actor)['is_auth']);
        self::assertTrue(UserLogic::info(20)['has_oa_auth']);
        self::assertFalse(UserLogic::info(20)['has_mnp_auth']);
        self::assertTrue(UserLogic::unbindWechat($actor));
        self::assertSame(0, UserLogic::center($actor)['is_auth']);
        self::assertFalse(UserLogic::info(20)['has_oa_auth']);
        self::assertSame(2, Db::name('user')->count());
        self::assertSame(99, (int)User::find(20)->user_money);
    }

    public function testStatusUsesIdentityTerminalRatherThanLoginSessionTerminal(): void
    {
        self::assertTrue(LoginLogic::createAuth(['user_id' => 20, 'openid' => 'oa-test', 'terminal' => UserTerminalEnum::WECHAT_OA]));
        $center = UserLogic::center(['user_id' => 20, 'tenant_id' => 1, 'terminal' => UserTerminalEnum::H5]);
        self::assertTrue($center['has_oa_auth']);
        self::assertFalse($center['has_mnp_auth']);
        self::assertFalse(UserLogic::info(10)['has_oa_auth']);
        self::assertTrue(UserLogic::info(10)['has_mnp_auth']);
        request()->tenantId = 2;
        self::assertSame(['has_oa_auth' => false, 'has_mnp_auth' => false], UserLogic::wechatBindingStatus(20));
    }

    public function testStatusReadsTenantShardAndIgnoresBaseRecords(): void
    {
        Db::name('tenant')->where('id', 1)->update(['tactics' => 1]);
        Db::execute('CREATE TABLE la_user_auth_first AS SELECT * FROM la_user_auth WHERE 1 = 0');
        Db::name('user_auth')->insert(['tenant_id' => 1, 'user_id' => 20, 'openid' => 'base-only', 'terminal' => 2]);
        self::assertFalse(UserLogic::wechatBindingStatus(20)['has_oa_auth']);
        Db::name('user_auth_first')->insert(['tenant_id' => 1, 'user_id' => 20, 'openid' => 'shard-only', 'terminal' => 2]);
        self::assertTrue(UserLogic::wechatBindingStatus(20)['has_oa_auth']);
    }
}
