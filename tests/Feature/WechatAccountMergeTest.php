<?php

use app\api\logic\LoginLogic;
use app\api\logic\UserLogic;
use app\api\service\WechatAccountService;
use app\api\service\WechatUserService;
use app\common\cache\UserTokenCache;
use app\common\enum\AdminTerminalEnum;
use app\common\enum\notice\NoticeEnum;
use app\common\enum\notice\SmsEnum;
use app\common\enum\user\UserTerminalEnum;
use app\common\model\user\User;
use PHPUnit\Framework\TestCase;
use think\facade\Config;
use think\facade\Db;

/**
 * Exercise the real ORM, SMS verification, identity transfer and token creation.
 * Each process uses only an in-memory database and a private temporary cache.
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class WechatAccountMergeTest extends TestCase
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

    private function bind(string $code = '123456', string $type = 'bind')
    {
        Db::name('tenant_sms_log')->insert([
            'tenant_id' => 1, 'mobile' => '13800000001', 'code' => '123456',
            'scene_id' => $type === 'bind' ? NoticeEnum::BIND_MOBILE_CAPTCHA : NoticeEnum::CHANGE_MOBILE_CAPTCHA,
            'send_status' => SmsEnum::SEND_SUCCESS, 'send_time' => time(),
        ]);
        return UserLogic::bindMobile([
            'user_id' => 10, 'terminal' => UserTerminalEnum::WECHAT_MMP,
            'mobile' => '13800000001', 'code' => $code, 'type' => $type,
        ]);
    }

    public function testCompletedProfileCanStillMergeIntoVerifiedPhoneAccount(): void
    {
        LoginLogic::updateUser(['nickname' => '已完善资料', 'avatar' => ''], 10);
        self::assertSame(0, (int)User::find(10)->is_new_user);
        $silentIdentity = (new WechatUserService(['openid' => 'test-mini-openid'], UserTerminalEnum::WECHAT_MMP))
            ->getResopnseByUserInfo()->getUserInfo();
        self::assertSame(10, (int)$silentIdentity['id']);
        self::assertSame('', $silentIdentity['mobile']);
        self::assertNotEmpty($silentIdentity['token']);
        $result = $this->bind();
        self::assertIsArray($result, UserLogic::getError());
        self::assertTrue($result['merged']);
        self::assertSame('13800000001', $result['mobile']);
        self::assertSame(20, (int)Db::name('user_auth')->where('id', 1)->value('user_id'));
        self::assertNotNull(Db::name('user')->where('id', 10)->value('delete_time'));
        self::assertSame(99, (int)User::find(20)->user_money);
        $identity = (new UserTokenCache())->getUserInfo($result['token']);
        self::assertSame(20, (int)$identity['user_id']);
        self::assertSame(1, (int)$identity['tenant_id']);
        self::assertSame(UserTerminalEnum::WECHAT_MMP, (int)$identity['terminal']);
        $nextLogin = (new WechatUserService(['openid' => 'test-mini-openid'], UserTerminalEnum::WECHAT_MMP))
            ->getResopnseByUserInfo()->getUserInfo();
        self::assertSame(20, (int)$nextLogin['id']);
    }

    public function testNewWechatAccountStillMerges(): void
    {
        self::assertTrue($this->bind()['merged']);
    }

    public function testInvalidSmsCannotMoveWechatIdentity(): void
    {
        self::assertFalse($this->bind('wrong'));
        self::assertSame('验证码错误', UserLogic::getError());
        self::assertSame(10, (int)Db::name('user_auth')->where('id', 1)->value('user_id'));
    }

    public function testUnusedPhoneBindsWithoutReplacingAccount(): void
    {
        Db::name('user')->where('id', 20)->update(['mobile' => '13800000002']);
        self::assertSame(['merged' => false], $this->bind());
        self::assertSame('13800000001', User::find(10)->mobile);
    }

    public function testExistingCredentialsPreventAccountReplacement(): void
    {
        foreach (['mobile' => '13800000003', 'password' => 'password-hash'] as $field => $value) {
            Db::name('user')->where('id', 10)->update([$field => $value]);
            self::assertFalse(WechatAccountService::isMergeableShadow(User::find(10)));
            self::assertFalse($this->bind());
            Db::name('user')->where('id', 10)->update([$field => '']);
        }
        self::assertSame(10, (int)Db::name('user_auth')->where('id', 1)->value('user_id'));
    }

    public function testAutoAccountPrefixAloneDoesNotAuthorizeMerge(): void
    {
        Db::name('user_auth')->delete(true);
        self::assertFalse($this->bind());
        self::assertNotNull(User::find(10));
    }

    public function testDisabledPhoneAccountCannotReceiveLoginToken(): void
    {
        Db::name('user')->where('id', 20)->update(['is_disable' => 1]);
        self::assertFalse($this->bind());
        self::assertSame('您的账号异常，请联系客服。', UserLogic::getError());
        self::assertSame(0, Db::name('user_session')->count());
        self::assertSame(10, (int)Db::name('user_auth')->where('id', 1)->value('user_id'));
    }

    public function testPhoneAccountFromOtherTenantIsNeverMerged(): void
    {
        Db::name('user')->where('id', 20)->update(['tenant_id' => 2]);
        self::assertSame(['merged' => false], $this->bind());
        self::assertSame(10, (int)Db::name('user_auth')->where('id', 1)->value('user_id'));
        self::assertSame(2, (int)Db::name('user')->where('id', 20)->value('tenant_id'));
    }

    public function testWechatIdentityFromOtherTenantDoesNotAuthorizeMerge(): void
    {
        Db::name('user_auth')->where('id', 1)->update(['tenant_id' => 2]);
        self::assertFalse($this->bind());
    }

    public function testReverseWechatBindingUsesTheSameCompletedProfileRule(): void
    {
        LoginLogic::updateUser(['nickname' => '已完善资料', 'avatar' => ''], 10);
        self::assertTrue(LoginLogic::createAuth([
            'user_id' => 20, 'openid' => 'test-mini-openid',
            'unionid' => 'test-unionid', 'terminal' => UserTerminalEnum::WECHAT_MMP,
        ]));
        self::assertSame(20, (int)Db::name('user_auth')->where('id', 1)->value('user_id'));
    }
}
