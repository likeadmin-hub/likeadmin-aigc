<?php
declare(strict_types=1);

// Exercise the actual avatar method; only config and external I/O are fixtures.
namespace app\common\service {
    class ConfigService {
        public static function get($type, $name = '', $default = null) { return $name === 'default' ? 'aliyun' : null; }
    }
}
namespace think { class Exception extends \Exception {} }
namespace app\common\service\storage {
    class StorageConfigService {
        public static array $config = ['scope'=>'platform', 'default'=>'aliyun', 'engine'=>['aliyun'=>['fixture'=>true]]];
        public static int $tenantId = 0;
        public static function getEffectiveConfig(?int $tenantId = null): array { self::$tenantId = $tenantId ?? 0; return self::$config; }
    }
    class Driver {
        public static array $used = [];
        public static bool $success = true;
        public function __construct($config) {
            if (!is_array($config['engine'] ?? null) || !isset($config['engine'][$config['default']])) throw new \RuntimeException('Missing effective engine configuration');
            self::$used = $config;
        }
        public function fetch($source, $path): bool { return self::$success; }
        public function getError(): string { return 'fixture upload failure'; }
    }
}
namespace {
    require dirname(__DIR__, 2) . '/app/common/enum/user/UserTerminalEnum.php';
    require dirname(__DIR__, 2) . '/app/api/service/WechatUserService.php';
    function request() { return (object)['tenantId'=>7]; }
    function download_file($url, $dir, $name) { return $dir.$name; }
    use app\common\service\storage\{StorageConfigService as Config, Driver};
    $checks = 0;
    foreach ([
        ['scope'=>'platform', 'default'=>'aliyun', 'engine'=>['aliyun'=>['fixture'=>true]]],
        ['scope'=>'tenant', 'default'=>'qcloud', 'engine'=>['qcloud'=>['fixture'=>true]]],
        ['scope'=>'tenant', 'default'=>'local', 'engine'=>['local'=>[]]],
    ] as $config) {
        Config::$config = $config;
        $service = new \app\api\service\WechatUserService(['openid'=>'fixture-only', 'headimgurl'=>'https://fixture.invalid/avatar.jpg'], 2);
        $path = $service->getAvatarByWechat();
        if (!preg_match('#^uploads/user/avatar/[a-f0-9]{32}\.jpeg$#D', $path)) throw new \RuntimeException('Managed avatar path is missing');
        $checks++;
        if (Config::$tenantId !== 7) throw new \RuntimeException('Tenant context lost');
        $checks++;
        if ($config['default'] !== 'local' && Driver::$used !== $config) throw new \RuntimeException('Did not use the effective scoped storage config');
        $checks++;
    }
    Config::$config = ['scope'=>'platform', 'default'=>'aliyun', 'engine'=>['aliyun'=>['fixture'=>true]]];
    Driver::$success = false;
    try { $service->getAvatarByWechat(); throw new \LogicException('Upload failure was swallowed'); }
    catch (\Exception $e) {
        if ($e->getMessage() !== '头像保存失败:fixture upload failure') throw $e;
        $checks++;
    }
    echo "WeChat avatar storage: $checks checks passed (platform/tenant cloud/local and failure; no external I/O)\n";
}
