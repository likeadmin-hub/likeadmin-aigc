<?php

namespace app\api\service;

use app\api\logic\LoginLogic;
use app\common\cache\UserTokenCache;
use app\common\model\user\User;
use app\common\model\user\UserAuth;
use app\common\service\ConfigService;
use app\common\service\SubmitLockService;
use app\common\service\wechat\PcWechatConfigService;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Log;
use WpOrg\Requests\Requests;

/** PC identities never create users or infer a login from another terminal's UnionID. */
class PcWechatService
{
    private const TTL = 600;
    private const PREFIX = 'pc_wechat_state_';
    public const NOT_BOUND = '请先使用手机号或账号密码登录，再到账号安全绑定微信';

    public function authorize(string $purpose, array $params, array $actor = []): array
    {
        $config = $this->config();
        $tenant = $this->tenant();
        if (!in_array($purpose, ['login', 'bind'], true)) throw new \RuntimeException('授权用途错误');
        if ($purpose === 'bind') $this->assertActor($actor);
        $proof = (string)($params['verifier'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/D', $proof)) throw new \RuntimeException('请从本站重新发起微信授权');
        $origin = (string)($params['origin'] ?? '');
        $allowedOrigin = PcWechatConfigService::origin($config['callback_url']);
        if ($origin !== $allowedOrigin || (request()->header('origin') && request()->header('origin') !== $origin)) {
            throw new \RuntimeException('当前站点与已配置的微信回调域不一致，请联系管理员');
        }
        $returnTo = self::returnPath((string)($params['return_to'] ?? '/ai'), $tenant);
        $state = bin2hex(random_bytes(32));
        $record = ['purpose' => $purpose, 'tenant_id' => $tenant, 'appid' => $config['app_id'],
            'fingerprint' => $this->fingerprint($config), 'proof' => hash('sha256', $proof),
            'user_id' => $purpose === 'bind' ? (int)$actor['user_id'] : 0,
            'session' => $purpose === 'bind' ? hash('sha256', $actor['token']) : '',
            'return_to' => $returnTo, 'expires_at' => time() + self::TTL];
        if (!Cache::set(self::PREFIX . $state, $record, self::TTL)) throw new \RuntimeException('授权暂不可用，请稍后重试');
        $callback = $config['callback_url'];
        parse_str((string)parse_url($callback, PHP_URL_QUERY), $query);
        if (!isset($query['tenant_id'])) $callback .= (str_contains($callback, '?') ? '&' : '?') . 'tenant_id=' . $tenant;
        return ['url' => 'https://open.weixin.qq.com/connect/qrconnect?' . http_build_query([
            'appid' => $config['app_id'], 'redirect_uri' => $callback, 'response_type' => 'code',
            'scope' => 'snsapi_login', 'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986) . '#wechat_redirect', 'state' => $state,
            'tenant_id' => $tenant, 'expires_in' => self::TTL];
    }

    public function complete(string $purpose, array $params, array $actor = []): array
    {
        $state = (string)($params['state'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/D', $state)) throw new \RuntimeException('授权已失效，请重新发起');
        $tenant = $this->tenant();
        try {
            [$record, $config] = $this->locked('state:' . $state, function () use ($state, $purpose, $params, $actor, $tenant) {
                $r = Cache::get(self::PREFIX . $state);
                if (!is_array($r) || $r['expires_at'] < time()) throw new \RuntimeException('授权已失效，请重新发起');
                if ($r['purpose'] !== $purpose || $r['tenant_id'] !== $tenant
                    || !hash_equals($r['proof'], hash('sha256', (string)($params['verifier'] ?? '')))) {
                    throw new \RuntimeException('授权上下文不匹配，请重新发起');
                }
                if ($purpose === 'bind') {
                    $this->assertActor($actor);
                    if ($r['user_id'] !== (int)$actor['user_id'] || !hash_equals($r['session'], hash('sha256', $actor['token']))) {
                        throw new \RuntimeException('登录账号或会话已变化，请重新绑定');
                    }
                }
                $c = $this->config();
                if (!hash_equals($r['fingerprint'], $this->fingerprint($c))) throw new \RuntimeException('微信配置已变化，请重新授权');
                if (empty($params['code']) || !is_string($params['code']) || strlen($params['code']) > 256) throw new \RuntimeException('微信未完成授权，请重新发起');
                if (!Cache::delete(self::PREFIX . $state)) throw new \RuntimeException('授权状态消费失败，请重新发起');
                return [$r, $c];
            });
            // No database transaction is held while contacting WeChat.
            $identity = $this->exchange($config, $params['code']);
            $result = $this->locked('identity:' . $tenant, function () use ($purpose, $actor, $identity, $config, $record) {
                if (!hash_equals($record['fingerprint'], $this->fingerprint($this->config()))) throw new \RuntimeException('微信配置已变化，请重新授权');
                if ($purpose === 'bind') $this->assertActor($actor);
                return Db::transaction(function () use ($purpose, $actor, $identity, $config) {
                    return $purpose === 'bind' ? $this->bind($config, $identity, $actor) : $this->login($config, $identity);
                });
            });
            $this->audit($purpose, 'success', $state);
            return $result + ['return_to' => $record['return_to'], 'tenant_id' => $tenant];
        } catch (\Throwable $e) {
            $this->audit($purpose, 'failed', $state);
            throw $e;
        }
    }

    protected function exchange(array $config, string $code): array
    {
        try {
            $response = Requests::get('https://api.weixin.qq.com/sns/oauth2/access_token?' . http_build_query([
                'appid' => $config['app_id'], 'secret' => $config['app_secret'], 'code' => $code,
                'grant_type' => 'authorization_code',
            ]), [], ['timeout' => 15, 'connect_timeout' => 5]);
        } catch (\Throwable $e) {
            throw new \RuntimeException('微信授权请求超时或网络异常，请重新发起');
        }
        $data = json_decode($response->body, true);
        if ($response->status_code !== 200 || !is_array($data)) throw new \RuntimeException('微信授权服务响应异常，请稍后重试');
        if (!empty($data['errcode'])) {
            $error = (int)$data['errcode'];
            Log::warning('pc_wechat oauth_error tenant=' . $this->tenant() . ' errcode=' . $error);
            throw new \RuntimeException(in_array($error, [40029, 40163], true) ? '微信授权已失效，请重新发起' : '微信授权失败，请联系管理员（' . $error . '）');
        }
        if (empty($data['openid']) || !is_string($data['openid']) || strlen($data['openid']) > 128 || empty($data['access_token'])) {
            throw new \RuntimeException('未获得有效微信身份，请重新授权');
        }
        // Login/binding only needs the verified identity, never the public profile or phone number.
        return ['openid' => $data['openid'], 'unionid' => (string)($data['unionid'] ?? '')];
    }

    private function login(array $config, array $identity): array
    {
        $auth = $this->authQuery($config['app_id'])->where('openid', $identity['openid'])->findOrEmpty();
        if ($auth->isEmpty()) throw new \RuntimeException(self::NOT_BOUND);
        $user = $this->activeUser((int)$auth->user_id);
        $token = UserTokenService::setToken($user, 4);
        if (empty($token['token'])) throw new \RuntimeException('登录失败，请重新发起');
        LoginLogic::updateLoginInfo((int)$user->id);
        return ['token' => $token['token'], 'mobile' => (string)$user->mobile,
            'require_mobile' => (int)ConfigService::get('login', 'coerce_mobile', config('project.login.coerce_mobile')) === 1 && empty($user->mobile)];
    }

    private function bind(array $config, array $identity, array $actor): array
    {
        $userId = (int)$actor['user_id'];
        $existing = $this->authQuery($config['app_id'])->where('openid', $identity['openid'])->findOrEmpty();
        if (!$existing->isEmpty()) {
            if ((int)$existing->user_id !== $userId) throw new \RuntimeException('该微信已绑定其他账号，请先在原账号解绑');
            return ['pc_wechat_bind_status' => 'bound', 'has_pc_auth' => true];
        }
        if (!$this->authQuery($config['app_id'])->where('user_id', $userId)->findOrEmpty()->isEmpty()) {
            throw new \RuntimeException('当前账号已绑定其他微信，请先解绑');
        }
        // Legacy rows can only be claimed by their logged-in owner after a fresh OAuth exchange.
        $legacy = $this->authQuery('')->where('openid', $identity['openid'])->findOrEmpty();
        if (!$legacy->isEmpty() && (int)$legacy->user_id !== $userId) throw new \RuntimeException('该微信存在其他账号的历史绑定，请先联系原账号处理');
        if (!$legacy->isEmpty()) {
            $legacy->appid = $config['app_id'];
            $legacy->unionid = $identity['unionid'];
            $legacy->save();
        } else {
            UserAuth::create(['tenant_id' => $this->tenant(), 'user_id' => $userId, 'terminal' => 4,
                'appid' => $config['app_id'], 'openid' => $identity['openid'], 'unionid' => $identity['unionid']]);
        }
        return ['pc_wechat_bind_status' => 'bound', 'has_pc_auth' => true];
    }

    public function unbind(array $actor): void
    {
        $this->assertActor($actor);
        $this->locked('identity:' . $this->tenant(), function () use ($actor) {
            Db::transaction(function () use ($actor) {
                $user = $this->activeUser((int)$actor['user_id']);
                if (empty($user->mobile) && empty($user->password)) throw new \RuntimeException('请先绑定手机号或设置登录密码');
                $appid = PcWechatConfigService::raw()['app_id'];
                if ($appid === '') throw new \RuntimeException('尚未配置网站应用');
                $this->authQuery($appid)->where('user_id', $user->id)->delete();
            });
        });
        $this->audit('unbind', 'success', bin2hex(random_bytes(8)));
    }

    public function bindingStatus(int $userId): array
    {
        $appid = PcWechatConfigService::raw()['app_id'];
        $bound = $appid !== '' && !$this->authQuery($appid)->where('user_id', $userId)->findOrEmpty()->isEmpty();
        $history = !$bound && !UserAuth::where(['tenant_id' => $this->tenant(), 'user_id' => $userId, 'terminal' => 4])->findOrEmpty()->isEmpty();
        return ['has_pc_auth' => $bound, 'pc_wechat_bind_status' => $bound ? 'bound' : ($history ? 'reconfirm_required' : 'unbound')];
    }

    private function authQuery(string $appid)
    {
        return UserAuth::where(['tenant_id' => $this->tenant(), 'terminal' => 4, 'appid' => $appid]);
    }

    private function activeUser(int $id): User
    {
        $user = User::where(['tenant_id' => $this->tenant(), 'id' => $id])->findOrEmpty();
        if ($user->isEmpty() || $user->is_disable) throw new \RuntimeException('账号不可用，请联系管理员');
        return $user;
    }

    private function assertActor(array $actor): void
    {
        if (empty($actor['token']) || empty($actor['user_id']) || (int)($actor['tenant_id'] ?? 0) !== $this->tenant()) throw new \RuntimeException('登录已失效，请重新登录');
        $live = (new UserTokenCache())->getUserInfo($actor['token']);
        if (!$live || (int)$live['user_id'] !== (int)$actor['user_id'] || (int)$live['tenant_id'] !== $this->tenant()) throw new \RuntimeException('登录会话已变化，请重新登录');
        $this->activeUser((int)$actor['user_id']);
    }

    private function config(): array
    {
        if (!PcWechatConfigService::status()['available']) throw new \RuntimeException('PC 微信登录未开启或配置不完整');
        return PcWechatConfigService::raw();
    }

    private function fingerprint(array $config): string { return hash('sha256', json_encode($config)); }
    private function tenant(): int
    {
        $id = (int)(request()->tenantId ?? 0);
        if ($id <= 0) throw new \RuntimeException('缺少有效租户');
        return $id;
    }

    public static function returnPath(string $path, int $tenant): string
    {
        $decoded = $path;
        for ($i = 0; $i < 3; $i++) $decoded = rawurldecode($decoded);
        if (!str_starts_with($decoded, '/') || str_starts_with($decoded, '//') || preg_match('/[\\\\\x00-\x20]/', $decoded) || strlen($path) > 2048) throw new \RuntimeException('返回地址不合法');
        $p = parse_url($decoded);
        if (!$p || isset($p['host']) || isset($p['scheme']) || preg_match('#(?:^|/)\.\.(?:/|$)#', $p['path'] ?? '')) throw new \RuntimeException('返回地址不合法');
        if (preg_match('#^/t/([0-9]+)(?:/|$)#', $p['path'] ?? '', $m) && (int)$m[1] !== $tenant) throw new \RuntimeException('不能返回其他租户');
        parse_str($p['query'] ?? '', $q);
        foreach (['tenant_id', 'tenantId'] as $key) if (isset($q[$key]) && (!is_scalar($q[$key]) || (string)$q[$key] !== (string)$tenant)) throw new \RuntimeException('不能返回其他租户');
        return $path;
    }

    /** MySQL advisory locks work across workers/hosts; SQLite tests use a local flock. */
    protected function locked(string $key, callable $fn)
    {
        $connection = Db::connect();
        $name = 'pcwx:' . substr(hash('sha256', $connection->getConfig('database') . ':' . $key), 0, 55);
        if ($connection->getConfig('type') === 'mysql') {
            $lock = $connection->query('SELECT GET_LOCK(?, 3) AS acquired', [$name]);
            if ((int)($lock[0]['acquired'] ?? 0) !== 1) throw new \RuntimeException('请求正在处理中，请稍后重试');
            try { return $fn(); } finally { $connection->query('SELECT RELEASE_LOCK(?)', [$name]); }
        }
        $handle = SubmitLockService::acquire($name);
        try { return $fn(); } finally { SubmitLockService::release($handle); }
    }

    private function audit(string $operation, string $result, string $state): void
    {
        Log::info('pc_wechat tenant=' . $this->tenant() . ' operation=' . $operation . ' result=' . $result . ' request=' . substr(hash('sha256', $state), 0, 16));
    }
}
