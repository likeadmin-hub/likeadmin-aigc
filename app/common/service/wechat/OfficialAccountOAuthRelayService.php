<?php
declare(strict_types=1);

namespace app\common\service\wechat;

use app\common\service\SubmitLockService;
use think\cache\driver\File;

/** User OAuth is distinct from the component's account-management callbacks. */
class OfficialAccountOAuthRelayService
{
    public const BROWSER_COOKIE = 'wechat_official_oauth_browser';
    public const STATE_COOKIE = 'wechat_official_oauth_state';
    private const STATE_TTL = 600;
    private const TICKET_TTL = 120;
    private const BASE_PATH = '/wechat/official-oauth/';
    private ?File $cache = null;

    public function start(int $tenantId, string $returnUrl, string $requestOrigin): array
    {
        $this->validateReturnUrl($tenantId, $returnUrl, $requestOrigin);
        $identity = $this->identity($tenantId);
        if (!str_starts_with($identity['platform_origin'], 'https://')) {
            throw new \RuntimeException('请先配置开放平台 HTTPS 回调域名');
        }
        $state = bin2hex(random_bytes(16));
        $browser = bin2hex(random_bytes(24));
        $this->write($this->key('state', $state), array_merge($identity, [
            'tenant_id' => $tenantId,
            'return_url' => $returnUrl,
            'browser_hash' => hash('sha256', $browser),
            'expires_at' => $this->now() + self::STATE_TTL,
        ]), self::STATE_TTL);
        return [
            'url' => $identity['platform_origin'] . self::BASE_PATH . 'authorize?state=' . $state,
            'browser_nonce' => $browser,
        ];
    }

    public function launch(string $state, string $requestOrigin): string
    {
        $context = $this->context($state, $requestOrigin);
        return 'https://open.weixin.qq.com/connect/oauth2/authorize?' . http_build_query([
            'appid' => $context['authorizer_appid'],
            'redirect_uri' => $context['platform_origin'] . self::BASE_PATH . 'callback',
            'response_type' => 'code',
            'scope' => 'snsapi_userinfo',
            'state' => $state,
            'component_appid' => $context['component_appid'],
        ], '', '&', PHP_QUERY_RFC3986) . '#wechat_redirect';
    }

    public function complete(string $state, string $code, string $stateCookie, string $requestOrigin): string
    {
        if ($code === '' || strlen($code) > 512 || !hash_equals($state, $stateCookie)) {
            throw new \RuntimeException('微信授权校验失败，请重新发起登录');
        }
        return $this->locked('state.' . hash('sha256', $state), function () use ($state, $code, $requestOrigin) {
            $context = $this->context($state, $requestOrigin);
            $this->assertIdentity($context);
            // Consume before a network call: an uncertain code exchange must
            // never be repeated by a concurrent or replayed callback.
            $this->remove($this->key('state', $state));
            $user = $this->exchange((int)$context['tenant_id'], $code);
            if (empty($user['openid'])) throw new \RuntimeException('微信用户信息获取失败');
            $ticket = 'woa_' . bin2hex(random_bytes(24));
            $this->write($this->key('ticket', $ticket), [
                'tenant_id' => $context['tenant_id'],
                'authorizer_appid' => $context['authorizer_appid'],
                'component_appid' => $context['component_appid'],
                'platform_origin' => $context['platform_origin'],
                'browser_hash' => $context['browser_hash'],
                'user' => $user,
                'expires_at' => $this->now() + self::TICKET_TTL,
            ], self::TICKET_TTL);
            return $this->returnUrl($context['return_url'], $ticket, $state);
        });
    }

    public function consume(int $tenantId, string $ticket, string $browserNonce): array
    {
        if (!preg_match('/^woa_[a-f0-9]{48}$/D', $ticket) || !preg_match('/^[a-f0-9]{48}$/D', $browserNonce)) {
            throw new \RuntimeException('微信登录凭证无效，请重新发起登录');
        }
        return $this->locked('ticket.' . hash('sha256', $ticket), function () use ($tenantId, $ticket, $browserNonce) {
            $key = $this->key('ticket', $ticket);
            $context = $this->read($key);
            if (!$context || (int)$context['expires_at'] <= $this->now()
                || (int)$context['tenant_id'] !== $tenantId
                || !hash_equals($context['browser_hash'], hash('sha256', $browserNonce))) {
                throw new \RuntimeException('微信登录凭证已失效，请重新发起登录');
            }
            $this->assertIdentity($context);
            $this->remove($key);
            return $context['user'];
        });
    }

    public static function cookieOptions(bool $platform = false): array
    {
        return ['expire' => self::STATE_TTL, 'path' => $platform ? self::BASE_PATH : '/',
            'secure' => true, 'httponly' => true, 'samesite' => 'lax'];
    }

    public static function navigationPage(string $target, bool $returning = false): string
    {
        $json = json_encode($target, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
        $safe = htmlspecialchars($target, ENT_QUOTES, 'UTF-8');
        $title = $returning ? '即将完成登录' : '正在前往微信';
        $description = $returning ? '微信授权已完成，正在返回应用' : '请在微信中完成授权后继续';
        return <<<HTML
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="referrer" content="no-referrer">
<title>微信登录</title>
<style>
:root{color-scheme:light;--primary:#9238e8;--primary-soft:#f3eafa;--ink:#242232;--muted:#706c7d;--surface:#fff;--canvas:#f8f7fb;--line:#ece8f1;font-family:-apple-system,BlinkMacSystemFont,"PingFang SC","Microsoft YaHei",sans-serif}
*{box-sizing:border-box}body{margin:0;min-height:100vh;min-height:100svh;display:grid;place-items:center;padding:32px 24px;padding:max(32px,env(safe-area-inset-top)) max(24px,env(safe-area-inset-right)) max(32px,env(safe-area-inset-bottom)) max(24px,env(safe-area-inset-left));background:var(--canvas);color:var(--ink)}
.login-card{width:100%;max-width:360px;padding:36px 28px 28px;border:1px solid var(--line);border-radius:24px;background:var(--surface);text-align:center;box-shadow:0 16px 48px rgba(51,30,78,.05)}
.mark{width:64px;height:64px;margin:0 auto 24px;display:grid;place-items:center;border-radius:20px;background:var(--primary-soft);color:var(--primary)}.mark svg{width:32px;height:32px}h1{font-size:23px;font-weight:600;line-height:1.4;letter-spacing:.02em;margin:0 0 10px}.description{font-size:14px;line-height:1.7;margin:0;color:var(--muted)}
.progress{display:flex;justify-content:center;gap:6px;height:6px;margin:25px 0 28px}.progress span{width:6px;height:6px;border-radius:50%;background:var(--primary);animation:breathe 1.2s ease-in-out infinite}.progress span:nth-child(2){animation-delay:.16s}.progress span:nth-child(3){animation-delay:.32s}
.continue{display:flex;align-items:center;justify-content:center;gap:8px;min-height:48px;padding:12px 18px;border-radius:13px;background:var(--primary);color:#fff;text-decoration:none;font-size:15px;font-weight:500;line-height:1.5}.continue svg{width:17px;height:17px}.continue:focus-visible{outline:3px solid var(--primary);outline-offset:4px}.hint{margin:16px 0 0;font-size:12px;line-height:1.7;color:var(--muted)}.is-stalled .progress span{animation:none;background:var(--line)}noscript p{font-size:13px;line-height:1.6;color:var(--muted)}
@keyframes breathe{0%,70%,100%{opacity:.25;transform:translateY(0)}35%{opacity:1;transform:translateY(-3px)}}@media(prefers-reduced-motion:reduce){.progress span{animation:none}}
@media(max-width:340px){body{padding-left:16px;padding-right:16px}.login-card{padding:30px 22px}h1{font-size:21px}}
</style>
</head>
<body>
<main class="login-card" aria-labelledby="login-title">
<div class="mark" aria-hidden="true"><svg viewBox="0 0 32 32" fill="none"><path d="M16 3.5 27 8v8.3c0 6-4.6 10.2-11 12.2C9.6 26.5 5 22.3 5 16.3V8L16 3.5Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="m11 16 3.4 3.5L21 12.8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
<div role="status" aria-live="polite"><h1 id="login-title">{$title}</h1><p class="description" id="login-description">{$description}</p></div>
<div class="progress" aria-hidden="true"><span></span><span></span><span></span></div>
<a class="continue" href="{$safe}" rel="noreferrer">继续登录<svg viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M4 10h12m-5-5 5 5-5 5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg></a>
<p class="hint">若未自动跳转，请点击继续登录</p>
<noscript><p>当前浏览器未启用自动跳转，请使用上方按钮继续。</p></noscript>
</main>
<script>
setTimeout(function(){document.body.classList.add('is-stalled');document.getElementById('login-title').textContent='暂未完成跳转';document.getElementById('login-description').textContent='请点击继续登录，或返回原页面重试';},12000);
location.replace({$json});
</script>
</body></html>
HTML;
    }

    private function context(string $state, string $requestOrigin): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $state)) throw new \RuntimeException('微信授权状态无效');
        $context = $this->read($this->key('state', $state));
        if (!$context || (int)$context['expires_at'] <= $this->now()) throw new \RuntimeException('微信授权状态已失效');
        if ($this->origin($requestOrigin) !== $context['platform_origin']) throw new \RuntimeException('微信授权回调域名无效');
        return $context;
    }

    private function assertIdentity(array $context): void
    {
        $identity = $this->identity((int)$context['tenant_id']);
        foreach (['authorizer_appid', 'component_appid', 'platform_origin'] as $field) {
            if (!hash_equals((string)$context[$field], (string)$identity[$field])) {
                throw new \RuntimeException('微信授权配置已变更，请重新发起登录');
            }
        }
    }

    private function validateReturnUrl(int $tenantId, string $url, string $requestOrigin): void
    {
        if ($tenantId <= 0 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)
            || $this->origin($url) !== $this->origin($requestOrigin)) {
            throw new \InvalidArgumentException('微信登录回跳地址必须属于当前租户站点');
        }
        $parts = parse_url($url);
        $path = (string)($parts['path'] ?? '/');
        if (!preg_match('#^/(?:mobile(?:/|$)|t/' . $tenantId . '/mobile(?:/|$))#D', $path)) {
            throw new \InvalidArgumentException('微信登录回跳地址必须是当前手机端页面');
        }
        foreach ([(string)($parts['query'] ?? ''), explode('?', (string)($parts['fragment'] ?? ''), 2)[1] ?? ''] as $query) {
            parse_str($query, $params);
            foreach (['tenant_id', 'tenantId'] as $name) {
                if (isset($params[$name]) && (!is_scalar($params[$name]) || (string)$params[$name] !== (string)$tenantId)) {
                    throw new \InvalidArgumentException('微信登录回跳租户不匹配');
                }
            }
        }
    }

    private function origin(string $url): string
    {
        $p = parse_url($url);
        if (!$p || !isset($p['scheme'], $p['host']) || isset($p['user']) || isset($p['pass'])
            || !in_array(strtolower($p['scheme']), ['http', 'https'], true)) {
            throw new \InvalidArgumentException('微信登录地址无效');
        }
        $scheme = strtolower($p['scheme']);
        $port = (int)($p['port'] ?? ($scheme === 'https' ? 443 : 80));
        return $scheme . '://' . strtolower($p['host']) . (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80) ? '' : ':' . $port);
    }

    private function returnUrl(string $url, string $ticket, string $state): string
    {
        $pieces = explode('#', $url, 2);
        $hashRoute = isset($pieces[1]) && str_starts_with($pieces[1], '/');
        $add = ['code' => $ticket, 'state' => $state];
        $merge = static function (string $part, array $extra): string {
            $p = explode('?', $part, 2);
            parse_str($p[1] ?? '', $query);
            unset($query['code'], $query['state']);
            $query = array_merge($query, $extra);
            return $p[0] . ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
        };
        $base = $merge($pieces[0], $hashRoute ? [] : $add);
        return $base . (isset($pieces[1]) ? '#' . ($hashRoute ? $merge($pieces[1], $add) : $pieces[1]) : '');
    }

    private function key(string $kind, string $value): string { return 'wechat.official_oauth.' . $kind . '.' . hash('sha256', $value); }
    protected function now(): int { return time(); }
    protected function identity(int $tenantId): array { return OpenPlatformService::authorizedOfficialOAuthIdentity($tenantId); }
    protected function exchange(int $tenantId, string $code): array { return OpenPlatformService::exchangeAuthorizedOfficialUserByCode($tenantId, $code); }
    private function cache(): File
    {
        // api and index have different runtime_path() values. A site-owned
        // directory keeps relay state shared regardless of cache init order.
        return $this->cache ??= new File(app(), ['path' => root_path() . 'runtime/wechat-official-oauth/cache']);
    }
    protected function read(string $key): ?array { $value = $this->cache()->get($key); return is_array($value) ? $value : null; }
    protected function write(string $key, array $value, int $ttl): void
    {
        if (!$this->cache()->set($key, $value, $ttl)) throw new \RuntimeException('微信授权状态保存失败，请稍后重试');
    }
    protected function remove(string $key): void
    {
        if (!$this->cache()->delete($key)) throw new \RuntimeException('微信授权凭证消费失败，请重新发起登录');
    }
    protected function locked(string $key, callable $action)
    {
        // Bound the number of persistent flock files while retaining atomic
        // consumption for every state/ticket handled by this installation.
        $lock = SubmitLockService::acquire('wechat.official_oauth.' . substr(hash('sha256', $key), 0, 3), 0, 0, true);
        try { return $action(); } finally { SubmitLockService::release($lock); }
    }
}
