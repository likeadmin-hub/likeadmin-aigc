<?php

use app\api\service\PcWechatService;
use app\api\service\UserTokenService;
use app\common\cache\UserTokenCache;
use app\common\enum\AdminTerminalEnum;
use app\common\model\user\User;
use app\common\service\wechat\PcWechatConfigService;
use PHPUnit\Framework\TestCase;
use think\facade\Cache;
use think\facade\Config;
use think\facade\Db;

class FakePcWechatService extends PcWechatService
{
    public int $calls = 0;
    protected function exchange(array $config, string $code): array
    {
        $this->calls++;
        if ($code === 'fail') throw new RuntimeException('模拟微信网络失败');
        return ['openid' => $code, 'unionid' => 'same-union'];
    }
}

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class PcWechatLoginTest extends TestCase
{
    private FakePcWechatService $service;
    private array $actor;
    private string $cachePath;
    private const APP = 'wx1234567890abcdef';
    private const PROOF = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        (new \think\App())->initialize();
        Config::set(['default' => 'pc_test', 'connections' => ['pc_test' => [
            'type' => 'sqlite', 'database' => ':memory:', 'prefix' => 'la_', 'fields_strict' => true, 'fields_cache' => false,
        ]]], 'database');
        $this->cachePath = sys_get_temp_dir() . '/pc-wechat-test-' . bin2hex(random_bytes(8)) . '/';
        Config::set(['default' => 'file', 'stores' => ['file' => ['type' => 'File', 'path' => $this->cachePath, 'prefix' => '']]], 'cache');
        request()->tenantId = 1;
        request()->source = AdminTerminalEnum::USER;
        foreach ([
            'tenant' => 'id INTEGER PRIMARY KEY, sn TEXT, tactics INTEGER DEFAULT 0, delete_time INTEGER',
            'tenant_config' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, type TEXT, name TEXT, value TEXT',
            'user' => "id INTEGER PRIMARY KEY, tenant_id INTEGER, sn TEXT, account TEXT, mobile TEXT DEFAULT '', password TEXT DEFAULT '', nickname TEXT DEFAULT '', avatar TEXT DEFAULT '', is_new_user INTEGER DEFAULT 0, is_disable INTEGER DEFAULT 0, user_money NUMERIC DEFAULT 0, login_time INTEGER, login_ip TEXT, create_time INTEGER, update_time INTEGER, delete_time INTEGER",
            'user_auth' => "id INTEGER PRIMARY KEY, tenant_id INTEGER, user_id INTEGER, appid TEXT DEFAULT '', openid TEXT, unionid TEXT DEFAULT '', terminal INTEGER, create_time INTEGER, update_time INTEGER, UNIQUE(tenant_id,terminal,appid,openid)",
            'user_session' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, user_id INTEGER, terminal INTEGER, token TEXT, expire_time INTEGER, create_time INTEGER, update_time INTEGER',
        ] as $table => $columns) Db::execute("CREATE TABLE la_{$table} ({$columns})");
        Db::name('tenant')->insertAll([['id' => 1, 'sn' => 'one'], ['id' => 2, 'sn' => 'two']]);
        Db::name('user')->insertAll([
            ['id' => 10, 'tenant_id' => 1, 'account' => 'alice', 'sn' => '11111111', 'mobile' => '13800000001', 'user_money' => 88],
            ['id' => 20, 'tenant_id' => 1, 'account' => 'bob', 'sn' => '22222222', 'mobile' => '13800000002', 'user_money' => 99],
            ['id' => 30, 'tenant_id' => 2, 'account' => 'carol', 'sn' => '33333333', 'mobile' => '13800000003', 'user_money' => 66],
        ]);
        PcWechatConfigService::save(['app_id' => self::APP, 'app_secret' => 'test-secret-only', 'pc_login_enabled' => 1, 'callback_url' => 'https://pc.example.test/wechat/callback']);
        $this->actor = UserTokenService::setToken(User::find(10), 4);
        $this->service = new FakePcWechatService();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->cachePath)) {
            $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->cachePath, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($items as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            rmdir($this->cachePath);
        }
    }

    private function start(string $purpose = 'bind', array $extra = [], ?array $actor = null): array
    {
        return $this->service->authorize($purpose, $extra + ['verifier' => self::PROOF, 'origin' => 'https://pc.example.test', 'return_to' => '/app/aigc_image?tenant_id=1'], $actor ?? $this->actor);
    }
    private function complete(array $start, string $purpose = 'bind', string $code = 'wx-openid', ?array $actor = null): array
    {
        return $this->service->complete($purpose, ['state' => $start['state'], 'verifier' => self::PROOF, 'code' => $code], $actor ?? $this->actor);
    }
    private function fails(callable $fn, string $contains): void
    {
        try { $fn(); self::fail('Expected failure: ' . $contains); }
        catch (RuntimeException $e) { self::assertStringContainsString($contains, $e->getMessage()); }
    }

    public function testBindLoginUnbindNeverCreatesUserOrChangesAssets(): void
    {
        $before = Db::name('user')->select()->toArray();
        self::assertSame('bound', $this->complete($this->start())['pc_wechat_bind_status']);
        self::assertSame($before, Db::name('user')->select()->toArray());
        self::assertSame('bound', $this->complete($this->start())['pc_wechat_bind_status']);
        self::assertSame(1, Db::name('user_auth')->count());
        $login = $this->complete($this->start('login'), 'login');
        $live = (new UserTokenCache())->getUserInfo($login['token']);
        self::assertSame(10, (int)$live['user_id']);
        self::assertSame(1, (int)$live['tenant_id']);
        self::assertSame(3, Db::name('user')->count());
        $this->service->unbind($live);
        $this->fails(fn() => $this->complete($this->start('login'), 'login'), PcWechatService::NOT_BOUND);
        self::assertSame(88, (int)User::find(10)->user_money);
    }
    public function testUnknownOrUnionOnlyIdentityCannotLogin(): void
    {
        Db::name('user_auth')->insert(['tenant_id' => 1, 'user_id' => 10, 'openid' => 'mini-openid', 'unionid' => 'same-union', 'terminal' => 1]);
        $this->fails(fn() => $this->complete($this->start('login'), 'login'), PcWechatService::NOT_BOUND);
        self::assertSame(3, Db::name('user')->count());
        self::assertSame(1, Db::name('user_auth')->count());
    }
    public function testLegacyRequiresLoggedInOwnerToReconfirm(): void
    {
        Db::name('user_auth')->insert(['tenant_id' => 1, 'user_id' => 10, 'openid' => 'wx-openid', 'terminal' => 4]);
        self::assertSame('reconfirm_required', $this->service->bindingStatus(10)['pc_wechat_bind_status']);
        $this->fails(fn() => $this->complete($this->start('login'), 'login'), PcWechatService::NOT_BOUND);
        $this->complete($this->start());
        self::assertSame(1, Db::name('user_auth')->count());
        self::assertSame(self::APP, Db::name('user_auth')->value('appid'));
    }
    public function testConflictsDoNotMoveIdentities(): void
    {
        $this->complete($this->start());
        $bob = UserTokenService::setToken(User::find(20), 4);
        $this->fails(fn() => $this->complete($this->start('bind', [], $bob), 'bind', 'wx-openid', $bob), '其他账号');
        $this->fails(fn() => $this->complete($this->start(), 'bind', 'other-openid'), '先解绑');
        self::assertSame(10, (int)Db::name('user_auth')->value('user_id'));
    }
    public function testReplayAndFailedExchangeConsumeState(): void
    {
        $s = $this->start(); $this->complete($s);
        $this->fails(fn() => $this->complete($s), '失效');
        $s = $this->start(); $this->fails(fn() => $this->complete($s, 'bind', 'fail'), '网络');
        $this->fails(fn() => $this->complete($s), '失效');
        self::assertSame(2, $this->service->calls);
    }
    public function testPurposeProofAndTenantAreCheckedBeforeExchange(): void
    {
        $s = $this->start();
        $this->fails(fn() => $this->complete($s, 'login'), '不匹配');
        $this->fails(fn() => $this->service->complete('bind', ['state' => $s['state'], 'code' => 'x', 'verifier' => str_repeat('b',64)], $this->actor), '不匹配');
        request()->tenantId = 2;
        $this->fails(fn() => $this->complete($s), '不匹配');
        self::assertSame(0, $this->service->calls);
    }
    public function testExpiredStateAndChangedConfig(): void
    {
        $s = $this->start(); $r = Cache::get('pc_wechat_state_' . $s['state']); $r['expires_at'] = time()-1;
        Cache::set('pc_wechat_state_' . $s['state'], $r, 600);
        $this->fails(fn() => $this->complete($s), '失效');
        $s = $this->start(); PcWechatConfigService::save(['app_secret' => 'new-secret']);
        $this->fails(fn() => $this->complete($s), '配置已变化');
        self::assertSame(0, $this->service->calls);
    }
    public function testLogoutAndAccountSwitchRejectBinding(): void
    {
        $s = $this->start(); $bob = UserTokenService::setToken(User::find(20), 4);
        $this->fails(fn() => $this->complete($s, 'bind', 'x', $bob), '会话已变化');
        UserTokenService::expireToken($this->actor['token']);
        $this->fails(fn() => $this->complete($s), '重新登录');
        self::assertSame(0, $this->service->calls);
    }
    public function testDisabledAccountAndNoAlternativeCredentialCannotUnbind(): void
    {
        $this->complete($this->start());
        Db::name('user')->where('id', 10)->update(['is_disable' => 1]);
        $this->fails(fn() => $this->complete($this->start('login'), 'login'), '不可用');
        Db::name('user')->where('id', 10)->update(['is_disable' => 0, 'mobile' => '']);
        $this->fails(fn() => $this->service->unbind($this->actor), '手机号或设置登录密码');
        self::assertSame(1, Db::name('user_auth')->count());
    }
    public function testUnbindKeepsOtherTerminalsAndOtherApps(): void
    {
        $this->complete($this->start());
        Db::name('user_auth')->insertAll([
            ['tenant_id' => 1, 'user_id' => 10, 'openid' => 'mini', 'terminal' => 1, 'appid' => ''],
            ['tenant_id' => 1, 'user_id' => 10, 'openid' => 'old', 'terminal' => 4, 'appid' => 'wxabcdef1234567890'],
        ]);
        $this->service->unbind($this->actor);
        self::assertSame(2, Db::name('user_auth')->count());
        $this->fails(fn() => $this->complete($this->start('login'), 'login'), PcWechatService::NOT_BOUND);
    }
    public function testSecretEncryptionMaskAndEnablement(): void
    {
        $raw = Db::name('tenant_config')->where('name', 'app_secret')->value('value');
        self::assertStringStartsWith('enc:v1:', $raw);
        self::assertStringNotContainsString('test-secret-only', $raw);
        $display = PcWechatConfigService::display();
        PcWechatConfigService::save(['app_secret' => $display['app_secret']]);
        self::assertSame('test-secret-only', PcWechatConfigService::raw()['app_secret']);
        PcWechatConfigService::save(['app_secret' => '', 'pc_login_enabled' => 0]);
        self::assertFalse(PcWechatConfigService::status()['available']);
        $this->fails(fn() => $this->start(), '未开启');
    }
    public function testUnsafeReturnAddressesAndWrongOriginRejected(): void
    {
        foreach (['//evil.test', '/%2fevil.test', '/\\evil.test', '/t/2/ai', '/ai?tenant_id=2', '/ai?tenant_id[]=1', '/a/../t/2/ai'] as $path) {
            $this->fails(fn() => $this->start('login', ['return_to' => $path]), str_contains($path, 'tenant_id') || str_starts_with($path, '/t/') ? '租户' : '地址');
        }
        $this->fails(fn() => $this->start('login', ['origin' => 'https://other.test']), '不一致');
        self::assertSame('/t/1/ai?tenant_id=1', PcWechatService::returnPath('/t/1/ai?tenant_id=1',1));
    }
    public function testChangingAppIdRequiresRebinding(): void
    {
        $this->complete($this->start());
        PcWechatConfigService::save(['app_id' => 'wxabcdef1234567890', 'app_secret' => 'new-secret']);
        self::assertSame('reconfirm_required', $this->service->bindingStatus(10)['pc_wechat_bind_status']);
        $this->fails(fn() => $this->complete($this->start('login'), 'login'), PcWechatService::NOT_BOUND);
        self::assertSame(1, Db::name('user_auth')->count());
    }
    public function testSplitTenantBindingUsesShardAndDoesNotTouchBaseIdentity(): void
    {
        foreach (['user', 'user_auth', 'user_session', 'tenant_config'] as $name) {
            $schema = Db::query("SELECT sql FROM sqlite_master WHERE name=?", ['la_' . $name])[0]['sql'];
            Db::execute(str_replace('CREATE TABLE la_' . $name . ' ', 'CREATE TABLE la_' . $name . '_one ', $schema));
            Db::execute('INSERT INTO la_' . $name . '_one SELECT * FROM la_' . $name);
        }
        Db::name('tenant')->where('id', 1)->update(['tactics' => 1]);
        $this->complete($this->start());
        self::assertSame(0, Db::name('user_auth')->count());
        self::assertSame(1, Db::name('user_auth_one')->count());
        self::assertSame('bound', $this->service->bindingStatus(10)['pc_wechat_bind_status']);
        $login = $this->complete($this->start('login'), 'login');
        self::assertSame(10, (int)(new UserTokenCache())->getUserInfo($login['token'])['user_id']);
        self::assertSame($login['token'], Db::name('user_session_one')->where('user_id', 10)->value('token'));
    }

}
