<?php
declare(strict_types=1);

namespace app\common\service\storage {
    // The only external boundary replaced here is the avatar upload itself.
    class Driver {
        public static int $uploads = 0;
        public function __construct(array $config) {
            if (!is_array($config['engine'][$config['default']] ?? null)) throw new \RuntimeException('Missing effective storage configuration');
        }
        public function fetch(string $source, string $target): bool { self::$uploads++; return true; }
    }
}
namespace {
    $root = dirname(__DIR__, 2);
    require $root . '/runtime-loader.php';
    (new \AigcRuntimeApp($root))->initialize();

    use app\api\logic\LoginLogic;
    use app\common\enum\AdminTerminalEnum;
    use app\common\model\user\{User, UserAuth, UserSession};
    use app\common\service\wechat\{OfficialAccountOAuthRelayService as Relay, OpenPlatformService};
    use think\facade\{Db, Cache, Config};

    $cache = sys_get_temp_dir() . '/oa-registration-' . bin2hex(random_bytes(8)) . '/';
    Config::set(['default'=>'file', 'stores'=>['file'=>['type'=>'File', 'path'=>$cache, 'prefix'=>'']]], 'cache');
    Cache::forgetDriver('file');
    Db::startTrans();
    try {
        if (!Db::name('tenant')->where('id', 1)->count()) throw new \RuntimeException('Local tenant fixture is unavailable');
        Db::name('tenant')->where('id', 1)->update(['allow_custom_storage'=>0]);
        foreach (['default'=>'aliyun', 'aliyun'=>json_encode(['domain'=>'https://managed.fixture.invalid'])] as $name=>$value) {
            $query = Db::name('config')->where(['type'=>'storage', 'name'=>$name]);
            if ($query->count()) $query->update(['value'=>$value]);
            else Db::name('config')->insert(['type'=>'storage', 'name'=>$name, 'value'=>$value]);
        }
        $platform = ['app_id'=>'wxabcdef1234567890', 'callback_url'=>'https://z.cn/wechat/open-platform/callback'];
        if (Db::name('wechat_open_platform')->where('id', 1)->count()) Db::name('wechat_open_platform')->where('id', 1)->update($platform);
        else Db::name('wechat_open_platform')->insert(array_merge(['id'=>1], $platform));
        if (!Db::name('wechat_authorizers')->where(['tenant_id'=>1,'authorizer_type'=>'official','authorization_status'=>1])->count()) {
            Db::name('wechat_authorizers')->insert(['tenant_id'=>1,'authorizer_appid'=>'wx1234567890abcdef','authorizer_type'=>'official','authorization_status'=>1]);
        }
        request()->tenantId = 1;
        request()->source = AdminTerminalEnum::USER;
        request()->withServer(['HTTPS'=>'on', 'HTTP_HOST'=>'tenant.fixture.invalid']);
        request()->setHost('tenant.fixture.invalid');
        $profile = ['openid'=>'oa-acceptance-' . bin2hex(random_bytes(12)), 'nickname'=>'acceptance fixture', 'headimgurl'=>'https://provider.fixture.invalid/avatar.jpg'];
        $relay = new class($profile) extends Relay {
            public function __construct(private array $profile) {}
            protected function exchange(int $tenantId, string $code): array { return $this->profile; }
        };
        $login = static function () use ($relay): array {
            $url = OpenPlatformService::authorizedOfficialCodeUrl(1, 'https://tenant.fixture.invalid/mobile/pages/login/login?scene=login');
            parse_str((string)parse_url($url, PHP_URL_QUERY), $state);
            $target = $relay->complete($state['state'], 'fixture-provider-code', $state['state'], 'https://z.cn');
            parse_str((string)parse_url($target, PHP_URL_QUERY), $query);
            $result = LoginLogic::oaLogin(['code'=>$query['code']]);
            if ($result === false) throw new \RuntimeException(LoginLogic::getError());
            return $result;
        };
        $first = $login();
        if (empty($first['token']) || empty($first['id'])) throw new \RuntimeException('New-user login failed');
        if (\app\common\service\storage\Driver::$uploads !== 1) throw new \RuntimeException('Avatar was not stored exactly once');
        $second = $login();
        if ($second['id'] !== $first['id'] || $second['token'] === $first['token']) throw new \RuntimeException('Relogin did not reuse the user and rotate the session');
        if (UserAuth::where('openid', $profile['openid'])->count() !== 1) throw new \RuntimeException('Duplicate identity created');
        if (UserSession::where(['user_id'=>$first['id'], 'terminal'=>2])->count() !== 1) throw new \RuntimeException('Duplicate OA session created');
        if (\app\common\service\storage\Driver::$uploads !== 1) throw new \RuntimeException('Relogin uploaded the existing avatar again');
        echo "OAuth full registration and relogin passed (actual LoginLogic, avatar with effective OSS configuration, identity/session reuse; all database changes rolled back, no external network)\n";
    } finally {
        Db::rollback();
        if (is_dir($cache)) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($cache, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            rmdir($cache);
        }
    }
}
