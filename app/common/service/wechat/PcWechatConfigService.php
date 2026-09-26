<?php

namespace app\common\service\wechat;

use app\common\service\ConfigService;
use InvalidArgumentException;

/** Website application credentials, independently scoped to the current tenant. */
class PcWechatConfigService
{
    public static function raw(): array
    {
        $secret = (string)ConfigService::get('open_platform', 'app_secret', '');
        return [
            'app_id' => (string)ConfigService::get('open_platform', 'app_id', ''),
            'app_secret' => str_starts_with($secret, 'enc:v1:') ? WechatCredentialService::decrypt(substr($secret, 7)) : $secret,
            'pc_login_enabled' => (int)ConfigService::get('open_platform', 'pc_login_enabled', 0),
            'callback_url' => (string)ConfigService::get('open_platform', 'pc_callback_url', ''),
            'revision' => (string)ConfigService::get('open_platform', 'pc_revision', ''),
        ];
    }

    public static function status(): array
    {
        $c = self::raw();
        $configured = $c['app_id'] !== '' && $c['app_secret'] !== '' && self::validCallback($c['callback_url']);
        return ['enabled' => $c['pc_login_enabled'] === 1, 'configured' => $configured,
            'available' => $configured && $c['pc_login_enabled'] === 1];
    }

    public static function display(): array
    {
        $c = self::raw();
        $c['app_secret'] = WechatCredentialService::mask($c['app_secret']);
        unset($c['revision']);
        return $c + self::status() + ['callback_domain' => parse_url($c['callback_url'], PHP_URL_HOST) ?: ''];
    }

    public static function save(array $params): void
    {
        $old = self::raw();
        $appid = trim((string)($params['app_id'] ?? $old['app_id']));
        $secret = trim((string)($params['app_secret'] ?? ''));
        $callback = trim((string)($params['callback_url'] ?? $old['callback_url']));
        $enabled = (int)($params['pc_login_enabled'] ?? $old['pc_login_enabled']);
        if ($secret === '' || $secret === WechatCredentialService::mask($old['app_secret'])) {
            $secret = $old['app_secret'];
            if ($appid !== $old['app_id']) {
                throw new InvalidArgumentException('更换网站 AppID 时请同时填写对应 AppSecret');
            }
        }
        if ($appid !== '' && !preg_match('/^wx[a-zA-Z0-9]{16}$/D', $appid)) {
            throw new InvalidArgumentException('请输入有效的网站应用 AppID');
        }
        if (str_contains($secret, '*')) throw new InvalidArgumentException('请填写真实 AppSecret');
        if ($callback !== '' && !self::validCallback($callback)) {
            throw new InvalidArgumentException('回调地址须为本站 HTTPS 地址，路径以 /wechat/callback 结尾，且仅可带当前 tenant_id');
        }
        if (!in_array($enabled, [0, 1], true)) throw new InvalidArgumentException('登录开关值错误');
        if ($enabled && ($appid === '' || $secret === '' || $callback === '')) {
            throw new InvalidArgumentException('请先完整配置网站 AppID、AppSecret 和回调地址');
        }
        $encrypted = WechatCredentialService::encrypt($secret);
        if ($secret !== '' && $encrypted === '') throw new \RuntimeException('凭证加密失败');
        ConfigService::set('open_platform', 'app_id', $appid);
        ConfigService::set('open_platform', 'app_secret', $secret === '' ? '' : 'enc:v1:' . $encrypted);
        ConfigService::set('open_platform', 'pc_callback_url', $callback);
        ConfigService::set('open_platform', 'pc_login_enabled', $enabled);
        ConfigService::set('open_platform', 'pc_revision', bin2hex(random_bytes(16)));
    }

    public static function validCallback(string $url): bool
    {
        $p = parse_url($url);
        if (!$p || ($p['scheme'] ?? '') !== 'https' || empty($p['host']) || isset($p['user'], $p['pass'])
            || isset($p['user']) || isset($p['fragment']) || preg_match('/[\\\\\x00-\x20]/', $url)) return false;
        if (!preg_match('#^(?:/t/([0-9]+))?(?:/pc)?/wechat/callback$#D', $p['path'] ?? '', $m)) return false;
        $tenant = (int)(request()->tenantId ?? 0);
        if (!empty($m[1]) && (int)$m[1] !== $tenant) return false;
        parse_str($p['query'] ?? '', $query);
        if (array_diff(array_keys($query), ['tenant_id'])) return false;
        return !isset($query['tenant_id']) || (is_scalar($query['tenant_id']) && (string)$query['tenant_id'] === (string)$tenant);
    }

    public static function origin(string $url): string
    {
        $p = parse_url($url);
        if (!$p || empty($p['scheme']) || empty($p['host'])) return '';
        return strtolower($p['scheme'] . '://' . $p['host']) . (isset($p['port']) && (int)$p['port'] !== 443 ? ':' . $p['port'] : '');
    }
}
