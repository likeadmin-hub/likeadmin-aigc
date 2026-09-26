<?php

use app\api\service\WechatUserService;
use app\common\cache\UserTokenCache;
use app\common\enum\AdminTerminalEnum;
use app\common\enum\user\UserTerminalEnum;
use app\common\model\user\User;
use PHPUnit\Framework\TestCase;
use think\facade\Config;
use think\facade\Db;

/**
 * Exercise WeChat identity resolution and the production session uniqueness contract.
 * Each process uses only an in-memory database and a private temporary cache.
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class WechatReloginTest extends TestCase
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
            'tenant_config' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, type TEXT, name TEXT, value TEXT',
            'user' => "id INTEGER PRIMARY KEY, tenant_id INTEGER, sn TEXT, account TEXT, mobile TEXT DEFAULT '', password TEXT DEFAULT '', nickname TEXT DEFAULT '', avatar TEXT DEFAULT '', is_new_user INTEGER DEFAULT 1, is_disable INTEGER DEFAULT 0, user_money NUMERIC DEFAULT 0, create_time INTEGER, update_time INTEGER, delete_time INTEGER",
            'user_auth' => "id INTEGER PRIMARY KEY, tenant_id INTEGER, user_id INTEGER, openid TEXT, unionid TEXT DEFAULT '', terminal INTEGER, create_time INTEGER, update_time INTEGER",
            'user_session' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, user_id INTEGER NOT NULL, terminal INTEGER, token TEXT UNIQUE, expire_time INTEGER, create_time INTEGER, update_time INTEGER, UNIQUE(user_id, terminal)',
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

    private function loginAgain(string $openid = 'test-mini-openid', string $unionid = ''): array
    {
        return (new WechatUserService(['openid' => $openid, 'unionid' => $unionid], UserTerminalEnum::WECHAT_MMP))
            ->getResopnseByUserInfo()->getUserInfo();
    }

    public function testLogoutAndReloginReuseExistingUniqueSession(): void
    {
        $before = Db::name('user')->select()->toArray();
        $first = $this->loginAgain();
        self::assertSame(10, (int)$first['id']);
        \app\api\service\UserTokenService::expireToken($first['token']);
        $second = $this->loginAgain();
        self::assertSame(10, (int)$second['id']);
        self::assertNotSame($first['token'], $second['token']);
        self::assertSame(1, Db::name('user_session')->count());
        self::assertSame(1, (int)Db::name('user_session')->value('tenant_id'));
        self::assertSame(10, (int)(new UserTokenCache())->getUserInfo($second['token'])['user_id']);
        self::assertFalse((new UserTokenCache())->getUserInfo($first['token']));
        self::assertSame($before, Db::name('user')->select()->toArray());
        self::assertSame(1, Db::name('user_auth')->count());
    }

    public function testSplitTenantCanReloginWithoutReadingBaseIdentity(): void
    {
        foreach (['user', 'user_auth', 'user_session', 'tenant_config'] as $name) {
            $schema = Db::query('SELECT sql FROM sqlite_master WHERE name=?', ['la_' . $name])[0]['sql'];
            Db::execute(str_replace('CREATE TABLE la_' . $name . ' ', 'CREATE TABLE la_' . $name . '_first ', $schema));
            Db::execute('INSERT INTO la_' . $name . '_first SELECT * FROM la_' . $name);
        }
        Db::name('tenant')->where('id', 1)->update(['tactics' => 1]);
        Db::name('user_auth')->delete(true);
        $first = $this->loginAgain();
        self::assertSame(10, (int)($first['id'] ?? 0));
        \app\api\service\UserTokenService::expireToken($first['token']);
        $second = $this->loginAgain();
        self::assertSame(10, (int)$second['id']);
        self::assertSame(1, Db::name('user_session_first')->count());
        self::assertSame(0, Db::name('user_session')->count());
        self::assertSame(2, Db::name('user_first')->count());
        Db::name('user_auth_first')->where('user_id', 10)->update(['unionid' => '']);
        $refreshed = (new WechatUserService(['openid' => 'test-mini-openid', 'unionid' => 'refreshed-union'], UserTerminalEnum::WECHAT_MMP))
            ->getResopnseByUserInfo()->authUserLogin()->getUserInfo();
        self::assertSame(10, (int)$refreshed['id']);
        self::assertSame('refreshed-union', Db::name('user_auth_first')->where('user_id', 10)->value('unionid'));
        self::assertSame(0, Db::name('user_auth')->count());

    }

    public function testForeignTenantIdentityCannotSelectCurrentTenantUser(): void
    {
        Db::name('user_auth')->insert(['tenant_id' => 2, 'user_id' => 20, 'openid' => 'foreign-openid', 'terminal' => 1]);
        self::assertSame([], $this->loginAgain('foreign-openid'));
        self::assertSame(0, Db::name('user_session')->count());
    }
    public function testOpenidMatchesCurrentTerminalBeforeUnionidFallback(): void
    {
        Db::name('user_auth')->insert(['tenant_id' => 1, 'user_id' => 20, 'openid' => 'test-mini-openid', 'unionid' => 'other-union', 'terminal' => 2]);
        $user = $this->loginAgain('test-mini-openid', 'other-union');
        self::assertSame(10, (int)$user['id']);
        self::assertSame(1, (int)$user['tenant_id']);
    }

    public function testUnionidFallbackCanAddCurrentTerminalWithoutCreatingAnotherUser(): void
    {
        $service = new WechatUserService(['openid' => 'new-oa-openid', 'unionid' => 'test-unionid'], UserTerminalEnum::WECHAT_OA);
        $user = $service->getResopnseByUserInfo()->authUserLogin()->getUserInfo();
        self::assertSame(10, (int)$user['id']);
        self::assertSame(2, Db::name('user')->count());
        self::assertSame(2, Db::name('user_auth')->count());
        $again = (new WechatUserService(['openid' => 'new-oa-openid'], UserTerminalEnum::WECHAT_OA))->getResopnseByUserInfo()->authUserLogin()->getUserInfo();
        self::assertSame(10, (int)$again['id']);
        self::assertSame(1, Db::name('user_session')->count());
    }

    public function testDisabledAndDeletedIdentityNeverBecomeNewAccounts(): void
    {
        Db::name('user')->where('id', 10)->update(['is_disable' => 1]);
        try { $this->loginAgain(); self::fail('Disabled account must fail'); }
        catch (\think\Exception $e) { self::assertStringContainsString('账号异常', $e->getMessage()); }
        Db::name('user')->where('id', 10)->update(['delete_time' => time()]);
        try { $this->loginAgain(); self::fail('Deleted account must fail'); }
        catch (\think\Exception $e) { self::assertStringContainsString('不可用', $e->getMessage()); }
        self::assertSame(0, Db::name('user_session')->count());
        self::assertSame(2, Db::name('user')->count());
    }

    public function testAmbiguousUnionidDoesNotSelectArbitraryAccount(): void
    {
        Db::name('user_auth')->insert(['tenant_id' => 1, 'user_id' => 20, 'openid' => 'other-openid', 'unionid' => 'test-unionid', 'terminal' => 2]);
        $this->expectExceptionMessage('微信身份关联多个账号');
        $this->loginAgain('unknown-openid', 'test-unionid');
    }

}
