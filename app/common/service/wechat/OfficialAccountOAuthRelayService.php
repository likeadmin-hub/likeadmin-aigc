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

    public static function navigationPage(string $target): string
    {
        $json = json_encode($target, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
        $safe = htmlspecialchars($target, ENT_QUOTES, 'UTF-8');
        return '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="referrer" content="no-referrer"><title>微信登录</title></head><body><p>正在继续微信登录…</p><a href="' . $safe . '">点击继续</a><script>location.replace(' . $json . ');</script></body></html>';
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
