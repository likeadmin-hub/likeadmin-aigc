<?php
declare(strict_types=1);

use app\common\cache\AdminTokenCache;
use app\platformapi\http\middleware\AuthMiddleware;
use app\platformapi\http\middleware\LoginMiddleware;
use app\platformapi\logic\LoginLogic;
use app\platformapi\service\AdminTokenService;
use think\facade\Db;

require dirname(__DIR__) . '/vendor/autoload.php';
(new think\App())->initialize();

app()->config->set([
    'default' => 'fixture',
    'auto_timestamp' => true,
    'datetime_format' => false,
    'connections' => ['fixture' => ['type' => 'sqlite', 'database' => ':memory:', 'prefix' => '']],
], 'database');
app()->config->set([
    'default' => 'file',
    'stores' => ['file' => ['type' => 'File', 'path' => sys_get_temp_dir() . '/platform-session-' . bin2hex(random_bytes(8))]],
], 'cache');
app()->cache->forgetDriver();

foreach ([
    "CREATE TABLE admin (id INTEGER PRIMARY KEY, root INTEGER, name TEXT, account TEXT, avatar TEXT, disable INTEGER, multipoint_login INTEGER, login_ip TEXT, login_time INTEGER, create_time INTEGER, update_time INTEGER, delete_time INTEGER)",
    "CREATE TABLE admin_role (admin_id INTEGER, role_id INTEGER)",
    "CREATE TABLE system_role (id INTEGER PRIMARY KEY, name TEXT, delete_time INTEGER)",
    "CREATE TABLE config (id INTEGER PRIMARY KEY, type TEXT, name TEXT, value TEXT)",
    "CREATE TABLE admin_session (id INTEGER PRIMARY KEY, admin_id INTEGER, terminal INTEGER, token TEXT UNIQUE, expire_time INTEGER, update_time INTEGER, UNIQUE(admin_id, terminal))",
    "CREATE TABLE platform_admin_session (token TEXT PRIMARY KEY, admin_id INTEGER, terminal INTEGER, login_ip TEXT, expire_time INTEGER, update_time INTEGER)",
    "INSERT INTO admin VALUES (1,1,'Fixture','fixture','fixture.png',0,1,'192.0.2.1',0,0,0,NULL)",
] as $sql) {
    Db::execute($sql);
}

function requireSession(bool $value, string $description): void
{
    if (!$value) {
        fwrite(STDERR, "FAIL: " . $description . PHP_EOL);
        exit(1);
    }
}

function loginAt(string $ip, int $terminal = 1): string
{
    requestAt($ip);
    return (new LoginLogic())->login(['account' => 'fixture', 'terminal' => $terminal])['token'];
}

function allowsAt(string $token, string $ip): bool
{
    $request = requestAt($ip);
    $request->withHeader(['token' => $token]);
    $request->controllerObject = new class {
        public function isNotNeedLogin(): bool { return false; }
    };
    return (new LoginMiddleware())->handle(
        $request,
        static fn ($request) => (new AuthMiddleware())->handle($request, static fn () => true)
    ) === true;
}

function requestAt(string $ip): app\Request
{
    $request = (new app\Request())->withServer(['REMOTE_ADDR' => $ip]);
    app()->instance('request', $request);
    return $request;
}

$cache = new AdminTokenCache();
$firstIp = '192.0.2.1';
$secondIp = '192.0.2.2';
$first = loginAt($firstIp);
$second = loginAt($secondIp);
requireSession($first !== $second, 'Same account and terminal at two IPs receive independent tokens');
requireSession(allowsAt($first, $firstIp) && allowsAt($second, $secondIp), 'Both browser sessions remain authenticated');
requireSession(!allowsAt($first, $secondIp), 'Another IP cannot use the first session token');
$cache->deleteAdminInfo($first);
requireSession(allowsAt($first, $firstIp) && !allowsAt($first, $secondIp), 'A cold cache preserves the original session IP');

$nearExpiry = time() + 120;
Db::name('platform_admin_session')->where('token', $first)->update(['expire_time' => $nearExpiry]);
requireSession(!allowsAt($first, $secondIp), 'A mismatched IP is still rejected near expiry');
requireSession((int)Db::name('platform_admin_session')->where('token', $first)->value('expire_time') === $nearExpiry, 'A mismatched IP cannot renew the session');
requireSession(allowsAt($first, $firstIp), 'The original IP can renew a near-expiry session');
requireSession((int)Db::name('platform_admin_session')->where('token', $first)->value('expire_time') > $nearExpiry, 'Successful renewal extends stored expiry');
requireSession(!allowsAt($first, $secondIp), 'Renewal preserves the session IP');
AdminTokenService::expireToken($first);
requireSession(!allowsAt($first, $firstIp) && allowsAt($second, $secondIp), 'Logout revokes only the current session');
requireSession(!AdminTokenService::overtimeToken($first), 'Renewal cannot revive a revoked session');

$legacy = str_repeat('a', 32);
Db::name('admin_session')->insert(['admin_id' => 1, 'terminal' => 1, 'token' => $legacy, 'expire_time' => time() + 600]);
requireSession(allowsAt($legacy, $secondIp), 'A valid legacy session can still use its original IP');
$third = loginAt($firstIp);
requireSession(allowsAt($legacy, $secondIp), 'A new login preserves an existing cached legacy session');

Db::name('admin')->where('id', 1)->update(['multipoint_login' => 0]);
$mobile = loginAt($secondIp, 2);
$single = loginAt($firstIp);
requireSession(!allowsAt($second, $secondIp) && !allowsAt($third, $firstIp) && !allowsAt($legacy, $secondIp), 'Single-login mode revokes all previous sessions for the same terminal');
requireSession(allowsAt($single, $firstIp) && allowsAt($mobile, $secondIp), 'Single-login mode preserves other terminal types');

AdminTokenService::expireAdminSessions(1);
requireSession(!allowsAt($single, $firstIp) && !allowsAt($mobile, $secondIp), 'Account-wide revocation covers every new session');
echo "PASS platform administrator session isolation, IP binding, renewal, logout and legacy compatibility\n";
