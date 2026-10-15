<?php
declare(strict_types=1);

// No database, credentials, or WeChat requests. Exercise the real relay with
// only its cache/clock/provider boundaries replaced by an in-memory fixture.
$file = dirname(__DIR__, 2) . '/app/common/service/wechat/OfficialAccountOAuthRelayService.php';
if (!is_file($file)) {
    fwrite(STDERR, "FAIL: authorized H5 login needs a platform-domain OAuth relay\n");
    exit(1);
}
require $file;

use app\common\service\wechat\OfficialAccountOAuthRelayService;

class RelayFixture extends OfficialAccountOAuthRelayService
{
    public array $records = [];
    public int $clock = 1800000000;
    public int $exchanges = 0;
    public bool $providerFails = false;
    public string $appid = 'wx1234567890abcdef';
    protected function now(): int { return $this->clock; }
    protected function identity(int $tenantId): array
    {
        return ['authorizer_appid' => $this->appid, 'component_appid' => 'wxabcdef1234567890', 'platform_origin' => 'https://platform.example.com'];
    }
    protected function read(string $key): ?array { return $this->records[$key] ?? null; }
    protected function write(string $key, array $value, int $ttl): void { $this->records[$key] = $value; }
    protected function remove(string $key): void { unset($this->records[$key]); }
    protected function locked(string $key, callable $action) { return $action(); }
    protected function exchange(int $tenantId, string $code): array
    {
        $this->exchanges++;
        if ($this->providerFails) throw new RuntimeException('provider failed');
        return ['openid' => 'fixture-openid', 'nickname' => 'fixture'];
    }
}

$checks = 0;
function check(bool $value, string $message): void
{
    global $checks;
    if (!$value) throw new RuntimeException($message);
    $checks++;
}
function rejects(callable $action, string $message): void
{
    try { $action(); } catch (RuntimeException | InvalidArgumentException $e) { check(true, $message); return; }
    throw new RuntimeException('Must reject: ' . $message);
}
function begin(RelayFixture $s, string $return = 'https://tenant.example.com/mobile/pages/login/login?scene=login&tenant_id=7'): array
{
    $r = $s->start(7, $return, 'https://tenant.example.com');
    parse_str((string)parse_url($r['url'], PHP_URL_QUERY), $q);
    return [$r, $q['state']];
}

$s = new RelayFixture();
[$r, $state] = begin($s);
check(parse_url($r['url'], PHP_URL_HOST) === 'platform.example.com', 'H5 must enter the configured platform domain');
$wechat = $s->launch($state, 'https://platform.example.com');
parse_str((string)parse_url($wechat, PHP_URL_QUERY), $q);
check($q['appid'] === $s->appid && $q['component_appid'] === 'wxabcdef1234567890', 'Keep the authorized account and component identities');
check($q['redirect_uri'] === 'https://platform.example.com/wechat/official-oauth/callback', 'WeChat must callback on the platform domain');
check($q['state'] === $state && $q['scope'] === 'snsapi_userinfo', 'Carry unpredictable state and the existing scope');
check($s->exchanges === 0, 'Launching does not exchange a code');
rejects(fn() => $s->launch($state, 'https://tenant.example.com'), 'launch on a tenant host');
rejects(fn() => $s->complete($state, 'wx-code', 'wrong-cookie', 'https://platform.example.com'), 'wrong platform browser');
rejects(fn() => $s->complete($state, 'wx-code', $state, 'https://tenant.example.com'), 'callback on a different origin');
check($s->exchanges === 0, 'Rejected callbacks never call WeChat');
$target = $s->complete($state, 'wx-code', $state, 'https://platform.example.com');
parse_str((string)parse_url($target, PHP_URL_QUERY), $out);
check(parse_url($target, PHP_URL_HOST) === 'tenant.example.com' && $out['scene'] === 'login' && $out['tenant_id'] === '7', 'Return to the original tenant and scene');
check(str_starts_with($out['code'], 'woa_') && $out['code'] !== 'wx-code', 'Return only a short-lived app ticket, never the provider code');
check(!str_contains($target, 'openid') && !str_contains($target, 'nickname'), 'No identity data in redirect URLs');
rejects(fn() => $s->complete($state, 'wx-code', $state, 'https://platform.example.com'), 'callback replay');
check($s->exchanges === 1, 'A code is exchanged only once');
rejects(fn() => $s->consume(8, $out['code'], $r['browser_nonce']), 'cross-tenant ticket');
rejects(fn() => $s->consume(7, $out['code'], 'wrong-browser'), 'ticket copied into another browser');
check($s->consume(7, $out['code'], $r['browser_nonce'])['openid'] === 'fixture-openid', 'Original tenant browser consumes the identity');
rejects(fn() => $s->consume(7, $out['code'], $r['browser_nonce']), 'ticket reuse');
rejects(fn() => $s->consume(7, 'arbitrary-provider-code', $r['browser_nonce']), 'raw code bypass');

foreach (['https://evil.example/mobile/', 'https://tenant.example.com.evil.example/mobile/', 'https://u@tenant.example.com/mobile/', 'javascript:alert(1)', "https://tenant.example.com/mobile/\r\nX:1", 'https://tenant.example.com/admin/', 'https://tenant.example.com/mobile/?tenant_id=8', 'https://tenant.example.com/t/8/mobile/'] as $url) {
    rejects(fn() => begin(new RelayFixture(), $url), 'unsafe callback ' . json_encode($url));
}

$s = new RelayFixture();
[$r, $state] = begin($s, 'https://tenant.example.com/mobile/?tenant_id=7#/pages/login/login?scene=bind&code=old&state=old');
$target = $s->complete($state, 'wx-code', $state, 'https://platform.example.com');
parse_str(explode('?', (string)parse_url($target, PHP_URL_FRAGMENT), 2)[1], $out);
check($out['scene'] === 'bind' && str_starts_with($out['code'], 'woa_') && $out['state'] !== 'old', 'Hash routing preserves the scene and replaces stale auth parameters');
check(parse_url($target, PHP_URL_QUERY) === 'tenant_id=7', 'Hash routing preserves the top-level tenant context');

$s = new RelayFixture();
[$r, $state] = begin($s);
$s->clock += 601;
rejects(fn() => $s->launch($state, 'https://platform.example.com'), 'expired state even if cache eviction is delayed');
rejects(fn() => $s->complete($state, 'wx-code', $state, 'https://platform.example.com'), 'expired callback');

$s = new RelayFixture();
[$r, $state] = begin($s);
$s->appid = 'wx0000000000000000';
rejects(fn() => $s->complete($state, 'wx-code', $state, 'https://platform.example.com'), 'authorizer replaced during OAuth');
check($s->exchanges === 0, 'Changed authorizer never receives the old code');

$s = new RelayFixture();
[$r, $state] = begin($s);
$target = $s->complete($state, 'wx-code', $state, 'https://platform.example.com');
parse_str((string)parse_url($target, PHP_URL_QUERY), $out);
$s->clock += 121;
rejects(fn() => $s->consume(7, $out['code'], $r['browser_nonce']), 'expired ticket');

$s = new RelayFixture();
[$r, $state] = begin($s);
$s->providerFails = true;
rejects(fn() => $s->complete($state, 'wx-code', $state, 'https://platform.example.com'), 'upstream failure');
rejects(fn() => $s->complete($state, 'wx-code', $state, 'https://platform.example.com'), 'retrying an uncertain provider code');
check($s->exchanges === 1, 'Do not replay provider calls after failure');

echo "Official-account OAuth relay: $checks checks passed\n";
