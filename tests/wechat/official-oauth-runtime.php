<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/runtime-loader.php';
(new AigcRuntimeApp($root))->initialize();

use app\common\service\wechat\OfficialAccountOAuthRelayService as Relay;
use app\common\service\wechat\OpenPlatformService;
use app\common\service\wechat\WeChatOaService;
use think\facade\Db;

if (($argv[1] ?? '') === '--seed-http') {
    $fixture = new class extends Relay {
        protected function identity(int $tenantId): array
        {
            return ['authorizer_appid' => 'wx1234567890abcdef', 'component_appid' => 'wxabcdef1234567890', 'platform_origin' => 'https://z.cn'];
        }
    };
    echo json_encode($fixture->start(99999123, 'https://tenant.example.com/mobile/?scene=login', 'https://tenant.example.com'));
    exit;
}
if (($argv[1] ?? '') === '--cleanup-http') {
    (new \think\cache\driver\File(app(), ['path' => root_path() . 'runtime/wechat-official-oauth/cache']))
        ->delete('wechat.official_oauth.state.' . hash('sha256', $argv[2]));
    exit;
}

Db::startTrans();
try {
    $tenantId = 99999123;
    if (Db::name('wechat_authorizers')->where('tenant_id', $tenantId)->count()) throw new RuntimeException('Fixture tenant is occupied');
    $config = ['app_id' => 'wxabcdef1234567890', 'callback_url' => 'https://z.cn/wechat/open-platform/callback'];
    if (Db::name('wechat_open_platform')->where('id', 1)->count()) Db::name('wechat_open_platform')->where('id', 1)->update($config);
    else Db::name('wechat_open_platform')->insert(array_merge(['id' => 1], $config));
    Db::name('wechat_authorizers')->insert(['tenant_id' => $tenantId, 'authorizer_appid' => 'wx1234567890abcdef', 'authorizer_type' => 'official', 'authorization_status' => 1]);
    request()->tenantId = $tenantId;
    request()->source = \app\common\enum\AdminTerminalEnum::USER;
    request()->withServer(['HTTPS' => 'on', 'HTTP_HOST' => 'tenant.example.com']);
    request()->setHost('tenant.example.com');
    $url = (new WeChatOaService())->getCodeUrl('https://tenant.example.com/mobile/pages/login/login?scene=login');
    if (parse_url($url, PHP_URL_HOST) !== 'z.cn') throw new RuntimeException('Actual OA service did not select platform relay');
    parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
    $browser = (string)request()->cookie(Relay::BROWSER_COOKIE, '');
    if (!preg_match('/^[a-f0-9]{48}$/D', $browser)) throw new RuntimeException('Actual API did not queue the tenant browser cookie');
    $relay = new class extends Relay {
        protected function exchange(int $tenantId, string $code): array { return ['openid' => 'runtime-fixture-only']; }
    };
    $target = $relay->complete($query['state'], 'fixture-provider-code', $query['state'], 'https://z.cn');
    parse_str((string)parse_url($target, PHP_URL_QUERY), $query);
    $profile = OpenPlatformService::authorizedOfficialUserByCode($tenantId, $query['code']);
    if ($profile['openid'] !== 'runtime-fixture-only') throw new RuntimeException('Actual OA login did not consume the relay ticket');
    if (request()->cookie(Relay::BROWSER_COOKIE)) throw new RuntimeException('Consumed browser cookie was not cleared');
    try {
        OpenPlatformService::authorizedOfficialUserByCode($tenantId, $query['code']);
        throw new LogicException('Ticket replay succeeded');
    } catch (RuntimeException $expected) {}
    echo "OAuth runtime integration passed (actual OA facade, database transaction rolled back, cookie lifecycle, one-time ticket; no WeChat network)\n";
} finally { Db::rollback(); }
