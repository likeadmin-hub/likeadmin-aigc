<?php

namespace app\common\service\wechat;

use app\common\model\wechat\WechatApiLog;
use app\common\model\wechat\WechatArtifact;
use app\common\model\wechat\WechatAuthorizer;
use app\common\model\wechat\WechatMnpReview;
use app\common\model\wechat\WechatMnpVersion;
use app\common\model\wechat\WechatOpenPlatform;
use app\common\model\wechat\WechatTemplate;
use app\common\model\wechat\WechatCredential;
use app\common\service\SubmitLockService;
use app\common\service\ConfigService;
use think\facade\Cache;
use WpOrg\Requests\Requests;

/** Open-platform facade. Legacy direct WeChat services are not touched. */
class OpenPlatformService
{
    private const API = 'https://api.weixin.qq.com/';
    private const SECRET_FIELDS = ['app_secret', 'token', 'encoding_aes_key', 'developer_secret', 'upload_private_key', 'upload_certificate', 'upload_private_pem'];
    private const CONFIG_MASK_FIELDS = ['app_secret', 'token', 'encoding_aes_key', 'developer_secret', 'upload_private_key', 'upload_certificate', 'upload_private_pem', 'component_verify_ticket', 'component_access_token'];

    private static function rawConfig(): array { return WechatOpenPlatform::withoutGlobalScope()->findOrEmpty(1)->toArray(); }
    /**
     * Execute a write operation once for an Idempotency-Key.
     * Only the operation result is cached; credentials and provider tokens are never stored here.
     */
    public static function runIdempotent(string $operation, string $key, int $tenantId, callable $callback)
    {
        $key = trim($key);
        if ($key === '') return $callback();
        $cacheKey = 'wechat.idempotency.' . sha1($operation . '|' . $tenantId . '|' . $key);
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && array_key_exists('result', $cached)) return $cached['result'];
        $lock = SubmitLockService::acquire('wechat.idempotency.lock.' . $operation . '.' . sha1($key), $tenantId, 0, true);
        try {
            $cached = Cache::get($cacheKey);
            if (is_array($cached) && array_key_exists('result', $cached)) return $cached['result'];
            $result = $callback();
            if (is_array($result) || is_bool($result) || is_int($result) || is_string($result) || $result === null) {
                Cache::set($cacheKey, ['result' => $result, 'stored_at' => time()], 86400);
            }
            return $result;
        } finally {
            SubmitLockService::release($lock);
        }
    }
    public static function config(): array
    {
        $row = self::rawConfig();
        foreach (self::CONFIG_MASK_FIELDS as $key) {
            if (array_key_exists($key, $row)) {
                $row[$key] = WechatCredentialService::mask(WechatCredentialService::decrypt($row[$key]));
            }
        }
        // The callback is a fixed application route. Keep an existing override for
        // compatibility, but do not make platform administrators maintain it.
        $row['callback_url_display'] = self::callbackUrl(self::rawConfig());
        return $row;
    }

    /**
     * Expose only whether the platform configuration is ready for authorization.
     * No credential values are returned to tenant clients.
     */
    public static function configStatus(): array
    {
        $config = self::rawConfig();
        $labels = [
            'app_id' => 'AppID',
            'app_secret' => 'AppSecret',
            'token' => '消息校验 Token',
            'encoding_aes_key' => '消息加密 Key',
        ];
        $missing = [];
        foreach ($labels as $field => $label) {
            $value = WechatCredentialService::decrypt($config[$field] ?? '') ?: (string)($config[$field] ?? '');
            if (trim($value) === '') {
                $missing[] = $label;
            }
        }
        return ['configured' => $missing === [], 'missing' => $missing];
    }

    public static function saveConfig(array $data): array
    {
        $old = self::rawConfig();
        $payload = ['app_id' => trim((string)($data['app_id'] ?? ($old['app_id'] ?? ''))), 'callback_url' => trim((string)($data['callback_url'] ?? ($old['callback_url'] ?? ''))), 'developer_app_id' => trim((string)($data['developer_app_id'] ?? ($old['developer_app_id'] ?? ''))), 'status' => (int)($data['status'] ?? ($old['status'] ?? 1)), 'update_time' => time()];
        foreach (self::SECRET_FIELDS as $key) if (array_key_exists($key, $data) && trim((string)$data[$key]) !== '' && !str_contains((string)$data[$key], '*')) $payload[$key] = WechatCredentialService::encrypt((string)$data[$key]);
        $row = WechatOpenPlatform::withoutGlobalScope()->findOrEmpty(1);
        if ($row->isEmpty()) { $payload['id'] = 1; $payload['create_time'] = time(); WechatOpenPlatform::withoutGlobalScope()->insert($payload); } else $row->save($payload);
        return self::config();
    }

    public static function startTicket(): array
    {
        $config = self::rawConfig(); self::requireConfig($config, ['app_id', 'app_secret', 'token', 'encoding_aes_key']);
        return self::request('cgi-bin/component/api_start_push_ticket', ['component_appid' => $config['app_id'], 'component_appsecret' => WechatCredentialService::decrypt($config['app_secret'])], 'ticket.start');
    }

    public static function componentAccessToken(bool $force = false): string
    {
        $lock = SubmitLockService::acquire('wechat.token.component', 0, 0, true);
        try {
            $config = self::rawConfig(); self::requireConfig($config, ['app_id', 'app_secret']); $now = time();
            $cached = WechatCredentialService::decrypt($config['component_access_token'] ?? '') ?: (string)($config['component_access_token'] ?? '');
            if (!$force && $cached !== '' && (int)($config['component_token_expire_time'] ?? 0) > $now + 60) return $cached;
            $ticket = WechatCredentialService::decrypt($config['component_verify_ticket'] ?? ''); if ($ticket === '') throw new \RuntimeException('尚未收到 component_verify_ticket，请先启动 Ticket 推送');
            $result = self::request('cgi-bin/component/api_component_token', ['component_appid' => $config['app_id'], 'component_appsecret' => WechatCredentialService::decrypt($config['app_secret']), 'component_verify_ticket' => $ticket], 'token.component');
            $token = (string)$result['component_access_token']; $ttl = max(60, (int)($result['expires_in'] ?? 7200) - 300);
            WechatOpenPlatform::withoutGlobalScope()->where('id', 1)->update(['component_access_token' => WechatCredentialService::encrypt($token), 'component_token_expire_time' => $now + $ttl, 'update_time' => $now]); Cache::set('wechat.open_platform.component_token', $token, $ttl); return $token;
        } finally { SubmitLockService::release($lock); }
    }

    public static function preAuthCode(): string
    {
        $config = self::rawConfig(); $result = self::request('cgi-bin/component/api_create_preauthcode', ['component_appid' => $config['app_id']], 'auth.preauth', ['component_access_token' => self::componentAccessToken()]); return (string)$result['pre_auth_code'];
    }

    public static function authUrl(?int $tenantId = null, ?string $authorizerType = null): array
    {
        $status = self::configStatus();
        if (!$status['configured']) {
            throw new \RuntimeException('请先完善开放平台配置：缺少 ' . implode('、', $status['missing']));
        }
        $config = self::rawConfig(); self::requireConfig($config, ['app_id']); $state = bin2hex(random_bytes(16)); $callback = self::callbackUrl($config);
        $authorizerType = in_array($authorizerType, ['official', 'miniprogram'], true) ? $authorizerType : null;
        Cache::set('wechat.open_platform.auth_state.' . $state, ['tenant_id' => $tenantId ?: 0, 'authorizer_type' => $authorizerType, 'created_at' => time()], 600);
        $query = ['component_appid' => $config['app_id'], 'pre_auth_code' => self::preAuthCode(), 'redirect_uri' => $callback, 'state' => $state];
        if ($authorizerType !== null) $query['auth_type'] = $authorizerType === 'official' ? 1 : 2;
        return ['url' => 'https://mp.weixin.qq.com/cgi-bin/componentloginpage?' . http_build_query($query), 'state' => $state, 'authorizer_type' => $authorizerType];
    }
    public static function consumeAuthState(string $state): array { $key = 'wechat.open_platform.auth_state.' . $state; $value = Cache::get($key); Cache::delete($key); if (!is_array($value) || time() - (int)($value['created_at'] ?? 0) > 600) throw new \RuntimeException('授权状态已失效'); return $value; }
    public static function saveVerifyTicket(string $ticket): void { if ($ticket === '') throw new \InvalidArgumentException('Ticket 为空'); WechatOpenPlatform::withoutGlobalScope()->where('id', 1)->update(['component_verify_ticket' => WechatCredentialService::encrypt($ticket), 'ticket_expire_time' => time() + 7200, 'update_time' => time()]); Cache::set('wechat.open_platform.verify_ticket', $ticket, 7200); }

    public static function queryAuthorization(string $authorizationCode): array
    {
        if ($authorizationCode === '') throw new \InvalidArgumentException('授权码不能为空'); $config = self::rawConfig(); $result = self::request('cgi-bin/component/api_query_auth', ['component_appid' => $config['app_id'], 'authorization_code' => $authorizationCode], 'auth.query', ['component_access_token' => self::componentAccessToken()]); $info = (array)($result['authorization_info'] ?? []); if (empty($info['authorizer_appid'])) throw new \RuntimeException('微信未返回授权账号'); return $info;
    }
    /**
     * Query the authorizer profile after authorization. The profile is the
     * reliable source for distinguishing a mini program from an official
     * account; authorization scope values alone are not sufficient.
     */
    public static function authorizerProfileByAppid(string $appid): array
    {
        $appid = trim($appid);
        if ($appid === '') throw new \InvalidArgumentException('授权账号 AppID 不能为空');
        $config = self::rawConfig();
        $result = self::request(
            'cgi-bin/component/api_get_authorizer_info',
            ['component_appid' => $config['app_id'], 'authorizer_appid' => $appid],
            'auth.info.callback',
            ['component_access_token' => self::componentAccessToken()]
        );
        return (array)($result['authorizer_info'] ?? []);
    }
    public static function markUnauthorized(string $appid): void { if ($appid !== '') WechatAuthorizer::withoutGlobalScope()->where('authorizer_appid', $appid)->update(['authorization_status' => 0, 'unbind_time' => time(), 'update_time' => time()]); }

    public static function authorizerToken(int $id, bool $force = false): string
    {
        $lock = SubmitLockService::acquire('wechat.token.authorizer.' . $id, 0, 0, true);
        try {
            $row = WechatAuthorizer::withoutGlobalScope()->findOrEmpty($id); if ($row->isEmpty() || (int)$row['authorization_status'] !== 1) throw new \RuntimeException('授权账号不存在或已失效'); $now = time();
            $cached = WechatCredentialService::decrypt($row['access_token_ciphertext'] ?? '') ?: (string)($row['access_token_ciphertext'] ?? '');
            if (!$force && (int)$row['access_token_expire_time'] > $now + 60 && $cached !== '') return $cached;
            $refresh = WechatCredentialService::decrypt($row['authorizer_refresh_token_ciphertext']); if ($refresh === '') throw new \RuntimeException('授权账号缺少刷新令牌'); $config = self::rawConfig();
            $result = self::request('cgi-bin/component/api_authorizer_token', ['component_appid' => $config['app_id'], 'authorizer_appid' => $row['authorizer_appid'], 'authorizer_refresh_token' => $refresh], 'token.authorizer', ['component_access_token' => self::componentAccessToken()]); $token = (string)$result['authorizer_access_token']; $ttl = max(60, (int)($result['expires_in'] ?? 7200) - 300);
            $row->save(['access_token_ciphertext' => WechatCredentialService::encrypt($token), 'access_token_expire_time' => $now + $ttl, 'update_time' => $now]); return $token;
        } finally { SubmitLockService::release($lock); }
    }

    public static function authorizerInfo(int $id): array
    {
        $row = WechatAuthorizer::withoutGlobalScope()->findOrEmpty($id); if ($row->isEmpty()) throw new \RuntimeException('授权账号不存在'); $result = self::request('cgi-bin/component/api_get_authorizer_info', ['component_appid' => self::rawConfig()['app_id'], 'authorizer_appid' => $row['authorizer_appid']], 'auth.info', ['component_access_token' => self::componentAccessToken()], (int)$row['tenant_id'], $id); $profile = (array)($result['authorizer_info'] ?? []);
        if ($profile) $row->save(['authorizer_name' => (string)($profile['nick_name'] ?? $row['authorizer_name']), 'principal_name' => (string)($profile['principal_name'] ?? $row['principal_name']), 'head_img' => (string)($profile['head_img'] ?? $row['head_img']), 'func_info' => json_encode($profile['func_info'] ?? [], JSON_UNESCAPED_UNICODE), 'last_sync_time' => time(), 'update_time' => time()]); return $profile;
    }

    public static function syncAuthorizers(): array
    {
        $items = self::authorizers();
        foreach ($items as $item) {
            try { self::authorizerInfo((int)$item['id']); } catch (\Throwable $ignored) { /* one stale authorizer must not block the others */ }
        }
        return self::authorizers();
    }

    public static function uploadTemplate(int $artifactId, int $draftId, string $description = ''): array
    {
        if ($draftId <= 0) throw new \InvalidArgumentException('draft_id 必须为正整数');
        $artifact = WechatArtifact::withoutGlobalScope()->findOrEmpty($artifactId); if ($artifact->isEmpty() || (int)$artifact['verify_status'] !== 1) throw new \RuntimeException('产物不存在或未通过校验');
        $source = self::artifactPath((string)$artifact['artifact_dir'], (string)$artifact['version']); $manifest = json_decode((string)$artifact['sha256_manifest'], true); if (!is_dir($source) || !is_array($manifest) || self::fileManifest($source) !== $manifest) throw new \RuntimeException('产物校验失败');
        $lock = SubmitLockService::acquire('wechat.template.upload.' . $artifactId, 0, 0);
        $row = WechatTemplate::withoutGlobalScope()->where('artifact_id', $artifactId)->where('template_version', $artifact['version'])->order('id desc')->findOrEmpty();
        if (!$row->isEmpty() && (string)$row['upload_status'] === 'success' && (int)$row['draft_id'] === $draftId && (string)$row['template_id'] !== '') {
            SubmitLockService::release($lock);
            return $row->toArray();
        }
        $payload = ['draft_id' => $draftId, 'template_version' => $artifact['version'], 'template_desc' => $description, 'artifact_id' => $artifactId, 'upload_status' => 'uploading', 'update_time' => time(), 'error_message' => ''];
        if ($row->isEmpty()) { $payload['create_time'] = time(); $row = WechatTemplate::create($payload); } else $row->save($payload);
        try {
            // The draft is created by the local WeChat developer tool. The server only
            // promotes that draft into the component template library.
            $template = self::request('wxa/addtotemplate', ['draft_id' => $draftId], 'template.add', ['component_access_token' => self::componentAccessToken()]);
            $templateId = (string)($template['template_id'] ?? ''); if ($templateId === '') throw new \RuntimeException('微信未返回模板 ID');
            $row->save(['template_id' => $templateId, 'upload_status' => 'success', 'upload_time' => time(), 'update_time' => time(), 'error_message' => '']);
            return $row->toArray();
        } catch (\Throwable $e) {
            $row->save(['upload_status' => 'failed', 'error_message' => mb_substr($e->getMessage(), 0, 2000), 'update_time' => time()]);
            throw $e;
        } finally {
            SubmitLockService::release($lock);
        }
    }

    private static function versionForTenant(int $tenantId, int $id): array
    {
        $row = WechatMnpVersion::withoutGlobalScope()->where(['id' => $id, 'tenant_id' => $tenantId])->findOrEmpty(); if ($row->isEmpty()) throw new \RuntimeException('版本不存在');
        if ((string)$row['upload_mode'] !== 'template' || (int)$row['authorizer_id'] <= 0) throw new \RuntimeException('手动配置版本请使用代码上传，不能执行开放平台发布操作');
        $authorizer = WechatAuthorizer::withoutGlobalScope()->where(['id' => $row['authorizer_id'], 'tenant_id' => $tenantId, 'authorization_status' => 1])->findOrEmpty(); if ($authorizer->isEmpty()) throw new \RuntimeException('授权小程序不存在'); return [$row, $authorizer];
    }
    public static function submitExperience(int $tenantId, int $id): array { $lock = SubmitLockService::acquire('wechat.version.experience.' . $id, $tenantId, 0); try { [$row, $authorizer] = self::versionForTenant($tenantId, $id); $template = WechatTemplate::withoutGlobalScope()->findOrEmpty((int)$row['template_id']); if ($template->isEmpty() || (string)$template['upload_status'] !== 'success') throw new \RuntimeException('模板不存在或未上传成功'); if (!in_array((string)$row['experience_status'], ['pending', 'failed'], true)) return $row->toArray(); $row->save(['experience_status' => 'running', 'update_time' => time()]); try { $extJson = self::templateExtJson($row, $authorizer); self::request('wxa/commit', ['template_id' => $template['template_id'], 'ext_json' => json_encode($extJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'user_version' => (string)$row['version'], 'user_desc' => (string)$row['description']], 'release.experience', ['access_token' => self::authorizerToken((int)$authorizer['id'])]); $row->save(['experience_status' => 'success', 'update_time' => time()]); } catch (\Throwable $e) { $row->save(['experience_status' => 'failed', 'update_time' => time()]); throw $e; } return $row->toArray(); } finally { SubmitLockService::release($lock); } }
    public static function submitAudit(int $tenantId, int $id): array { $lock = SubmitLockService::acquire('wechat.version.audit.' . $id, $tenantId, 0); try { [$row, $authorizer] = self::versionForTenant($tenantId, $id); if ((string)$row['experience_status'] !== 'success') throw new \RuntimeException('请先提交体验版'); if ((string)$row['audit_status'] === 'pending') return $row->toArray(); if ((string)$row['audit_status'] === 'rejected') throw new \RuntimeException('审核已驳回，请新建版本后重新提交'); $result = self::request('wxa/submit_audit', ['item_list' => self::auditItems($row)], 'release.audit', ['access_token' => self::authorizerToken((int)$authorizer['id'])]); $row->save(['audit_status' => 'pending', 'update_time' => time()]); WechatMnpReview::withoutGlobalScope()->create(['version_id' => $id, 'audit_no' => (string)($result['auditid'] ?? ''), 'audit_status' => 'pending', 'reason' => '', 'detail' => '', 'response_summary' => json_encode(['auditid' => $result['auditid'] ?? ''], JSON_UNESCAPED_UNICODE), 'submit_time' => time(), 'create_time' => time()]); return $row->toArray(); } finally { SubmitLockService::release($lock); } }
    public static function queryAudit(int $tenantId, int $id): array { [$row, $authorizer] = self::versionForTenant($tenantId, $id); $review = WechatMnpReview::withoutGlobalScope()->where('version_id', $id)->order('id desc')->findOrEmpty(); if ($review->isEmpty() || (string)$review['audit_no'] === '') throw new \RuntimeException('暂无审核记录'); $result = self::request('wxa/get_auditstatus', ['auditid' => (int)$review['audit_no']], 'release.audit.status', ['access_token' => self::authorizerToken((int)$authorizer['id'])]); $status = (int)($result['status'] ?? -1); $mapped = [0 => 'approved', 1 => 'rejected', 2 => 'pending', 3 => 'rejected']; $auditStatus = $mapped[$status] ?? 'pending'; $review->save(['audit_status' => $auditStatus, 'reason' => (string)($result['reason'] ?? ''), 'detail' => json_encode($result, JSON_UNESCAPED_UNICODE), 'finish_time' => $auditStatus === 'pending' ? 0 : time()]); $row->save(['audit_status' => $auditStatus, 'update_time' => time()]); return $row->toArray(); }
    public static function releaseVersion(int $tenantId, int $id): array { $lock = SubmitLockService::acquire('wechat.version.release.' . $id, $tenantId, 0); try { [$row, $authorizer] = self::versionForTenant($tenantId, $id); if ((string)$row['audit_status'] !== 'approved') throw new \RuntimeException('审核尚未通过'); if ((string)$row['release_status'] === 'released') return $row->toArray(); self::request('wxa/release', [], 'release.publish', ['access_token' => self::authorizerToken((int)$authorizer['id'])]); $row->save(['release_status' => 'released', 'update_time' => time()]); return $row->toArray(); } finally { SubmitLockService::release($lock); } }
    public static function rollbackVersion(int $tenantId, int $id, int $fromId = 0): array { $lock = SubmitLockService::acquire('wechat.version.rollback.' . $id, $tenantId, 0); try { [$row, $authorizer] = self::versionForTenant($tenantId, $id); if ((string)$row['release_status'] !== 'released') throw new \RuntimeException('当前版本未发布'); self::request('wxa/revertcoderelease', [], 'release.rollback', ['access_token' => self::authorizerToken((int)$authorizer['id'])]); $row->save(['release_status' => 'rolled_back', 'rollback_from_id' => $fromId, 'update_time' => time()]); return $row->toArray(); } finally { SubmitLockService::release($lock); } }

    private static function request(string $path, array $payload, string $apiName, array $query = [], int $tenantId = 0, int $authorizerId = 0): array
    {
        $url = self::API . ltrim($path, '/'); if ($query) $url .= '?' . http_build_query($query); $requestId = bin2hex(random_bytes(12)); $started = microtime(true);
        try { $response = Requests::post($url, ['Content-Type' => 'application/json'], json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ['timeout' => 30]); $body = json_decode((string)$response->body, true); if (!is_array($body)) throw new \RuntimeException('微信接口返回格式错误'); $code = (int)($body['errcode'] ?? 0); self::logApi($requestId, $apiName, $code, $started, $code === 0 ? 'success' : 'failed', $tenantId, $authorizerId); if ($code !== 0) throw new \RuntimeException('微信接口调用失败：' . (string)($body['errmsg'] ?? $code)); return $body; } catch (\Throwable $e) { self::logApi($requestId, $apiName, -1, $started, 'failed', $tenantId, $authorizerId); throw $e; }
    }

    private static function callbackUrl(array $config): string
    {
        $callback = trim((string)($config['callback_url'] ?? ''));
        return $callback !== '' ? $callback : (string)url('/wechat/open-platform/callback', [], false, true);
    }
    private static function auditItems($row): array
    {
        $ext = json_decode((string)$row['ext_json'], true); $items = is_array($ext) && isset($ext['item_list']) && is_array($ext['item_list']) ? $ext['item_list'] : [];
        if (!$items) throw new \RuntimeException('请在版本配置中填写审核项目');
        return array_values(array_filter($items, static fn($item) => is_array($item) && !empty($item['address'])));
    }
    private static function logApi(string $requestId, string $apiName, int $code, float $started, string $result, int $tenantId = 0, int $authorizerId = 0): void { try { WechatApiLog::withoutGlobalScope()->insert(['request_id' => $requestId, 'tenant_id' => $tenantId, 'authorizer_id' => $authorizerId, 'api_name' => $apiName, 'wechat_code' => $code, 'elapsed_ms' => (int)((microtime(true) - $started) * 1000), 'retry_count' => 0, 'result' => $result, 'create_time' => time()]); } catch (\Throwable $ignored) {} }
    private static function requireConfig(array $config, array $fields): void { foreach ($fields as $field) if (trim(WechatCredentialService::decrypt($config[$field] ?? '') ?: (string)($config[$field] ?? '')) === '') throw new \RuntimeException('请先完善开放平台配置'); }

    public static function registerArtifact(string $version, string $sourceSha = ''): array
    {
        if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) throw new \InvalidArgumentException('版本号格式错误'); $root = self::artifactPath('runtime/wechat-artifacts/' . $version, $version); if (!is_dir($root)) $root = self::artifactPath('mp-weixin.pre-release-' . $version, $version); if (!is_dir($root)) throw new \RuntimeException('产物目录不存在'); $files = self::fileManifest($root); foreach (['app.json', 'project.config.json'] as $required) if (!isset($files[$required])) throw new \RuntimeException('缺少关键文件: ' . $required);
        $metadataPath = $root . '/.artifact.meta.json'; $metadata = is_file($metadataPath) ? json_decode((string)file_get_contents($metadataPath), true) : null; $manifestHash = hash('sha256', json_encode($files, JSON_UNESCAPED_SLASHES)); if (!is_array($metadata) || (string)($metadata['version'] ?? '') !== $version || (int)($metadata['file_count'] ?? -1) !== count($files) || (string)($metadata['sha256'] ?? '') !== $manifestHash || (array)($metadata['files'] ?? []) !== $files) throw new \RuntimeException('产物元数据校验失败，请重新生成版本产物'); if ($sourceSha !== '' && (string)($metadata['source_sha'] ?? '') !== $sourceSha) throw new \RuntimeException('产物源提交 SHA 与元数据不一致'); $sourceSha = (string)($metadata['source_sha'] ?? $sourceSha);
        $relativeDir = str_starts_with($root, root_path() . 'runtime' . DIRECTORY_SEPARATOR) ? 'runtime/wechat-artifacts/' . $version : 'mp-weixin.pre-release-' . $version;
        $payload = ['version' => $version, 'artifact_dir' => $relativeDir, 'source_sha' => $sourceSha, 'file_count' => count($files), 'sha256_manifest' => json_encode($files, JSON_UNESCAPED_SLASHES), 'built_at' => strtotime((string)($metadata['built_at'] ?? '')) ?: time(), 'verify_status' => 1, 'update_time' => time()]; $row = WechatArtifact::withoutGlobalScope()->where('version', $version)->findOrEmpty(); if ($row->isEmpty()) { $payload['promoted'] = 0; $payload['create_time'] = time(); return WechatArtifact::create($payload)->toArray(); } $row->save($payload); return $row->toArray();
    }
    private static function fileManifest(string $root): array { $files = []; $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)); foreach ($iterator as $file) { if (!$file->isFile() || $file->getFilename() === '.artifact.meta.json') continue; $relative = ltrim(str_replace($root, '', $file->getPathname()), DIRECTORY_SEPARATOR); $files[str_replace(DIRECTORY_SEPARATOR, '/', $relative)] = hash_file('sha256', $file->getPathname()); } ksort($files); return $files; }
    /** Resolve both new archived paths and legacy public paths during migration. */
    private static function artifactPath(string $artifactDir, string $version = ''): string
    {
        $artifactDir = trim(str_replace('\\', '/', $artifactDir), '/');
        if ($artifactDir === 'mp-weixin') return root_path() . 'public/mp-weixin';
        if (preg_match('/^runtime\/wechat-artifacts\/(\d+\.\d+\.\d+)$/', $artifactDir, $match)) {
            return root_path() . 'runtime/wechat-artifacts/' . $match[1];
        }
        if (preg_match('/^mp-weixin\\.pre-release-(\\d+\\.\\d+\\.\\d+)$/', basename($artifactDir), $match)) {
            $version = $version !== '' ? $version : $match[1];
            $archived = root_path() . 'runtime/wechat-artifacts/' . $version;
            if (is_dir($archived)) return $archived;
            return root_path() . 'public/' . basename($artifactDir);
        }
        return root_path() . 'public/' . basename($artifactDir);
    }
    /** Build a consistent presentation row for a registered or discovered artifact. */
    private static function inspectArtifact(array $row, ?array $formalManifest): array
    {
        $version = (string)($row['version'] ?? '');
        $directory = self::artifactPath((string)($row['artifact_dir'] ?? ''), $version);
        $files = is_dir($directory) ? self::fileManifest($directory) : [];
        $metadataPath = $directory . '/.artifact.meta.json';
        $metadata = is_file($metadataPath) ? json_decode((string)file_get_contents($metadataPath), true) : null;
        $manifestHash = hash('sha256', json_encode($files, JSON_UNESCAPED_SLASHES));
        $verifyMessage = '校验通过';
        $valid = true;
        if (!isset($files['app.json'], $files['project.config.json'])) {
            $valid = false;
            $verifyMessage = '缺少关键文件';
        } elseif (!is_array($metadata)) {
            $valid = false;
            $verifyMessage = '缺少产物元数据';
        } elseif ((string)($metadata['version'] ?? '') !== $version) {
            $valid = false;
            $verifyMessage = '版本元数据不匹配';
        } elseif ((int)($metadata['file_count'] ?? -1) !== count($files)) {
            $valid = false;
            $verifyMessage = '文件数量不匹配';
        } elseif ((string)($metadata['sha256'] ?? '') !== $manifestHash || (array)($metadata['files'] ?? []) !== $files) {
            $valid = false;
            $verifyMessage = '文件清单校验失败';
        }
        return array_merge($row, [
            'id' => (int)($row['id'] ?? 0),
            'version' => $version,
            'artifact_dir' => (string)($row['artifact_dir'] ?? ''),
            'source_sha' => (string)($metadata['source_sha'] ?? ($row['source_sha'] ?? '')),
            'file_count' => count($files),
            'sha256_manifest' => json_encode($files, JSON_UNESCAPED_SLASHES),
            'built_at' => strtotime((string)($metadata['built_at'] ?? '')) ?: (int)@filemtime($directory),
            'verify_status' => $valid ? 1 : 0,
            'verify_message' => $verifyMessage,
            'promoted' => $valid && (int)($row['promoted'] ?? 0) === 1 && $formalManifest === $files ? 1 : 0,
        ]);
    }

    /**
     * Return registered artifacts and discover versioned build directories that
     * have not been registered yet. Discovery is deliberately limited to the
     * two controlled artifact roots and never scans the formal mp-weixin tree.
     */
    public static function artifacts(): array
    {
        $artifacts = [];
        $formalDirectory = root_path() . 'public/mp-weixin';
        $formalManifest = is_dir($formalDirectory) ? self::fileManifest($formalDirectory) : null;
        $registered = [];
        foreach (WechatArtifact::withoutGlobalScope()->order('version desc')->select()->toArray() as $row) {
            $version = (string)($row['version'] ?? '');
            $registered[$version] = true;
            $artifacts[] = self::inspectArtifact($row, $formalManifest);
        }

        $discovered = [];
        $runtimeRoot = root_path() . 'runtime/wechat-artifacts';
        if (is_dir($runtimeRoot)) {
            foreach (scandir($runtimeRoot) ?: [] as $name) {
                if ($name === '.' || $name === '..' || !preg_match('/^\d+\.\d+\.\d+$/', $name)) continue;
                if (is_dir($runtimeRoot . DIRECTORY_SEPARATOR . $name)) {
                    $discovered[$name] = ['id' => 0, 'version' => $name, 'artifact_dir' => 'runtime/wechat-artifacts/' . $name, 'promoted' => 0];
                }
            }
        }
        $publicRoot = root_path() . 'public';
        if (is_dir($publicRoot)) {
            foreach (scandir($publicRoot) ?: [] as $name) {
                if (!preg_match('/^mp-weixin\.pre-release-(\d+\.\d+\.\d+)$/', $name, $match)) continue;
                if (!isset($discovered[$match[1]]) && is_dir($publicRoot . DIRECTORY_SEPARATOR . $name)) {
                    $discovered[$match[1]] = ['id' => 0, 'version' => $match[1], 'artifact_dir' => $name, 'promoted' => 0];
                }
            }
        }
        foreach ($discovered as $version => $row) {
            if (!isset($registered[$version])) $artifacts[] = self::inspectArtifact($row, $formalManifest);
        }

        usort($artifacts, static fn(array $left, array $right): int => version_compare((string)$right['version'], (string)$left['version']));
        return $artifacts;
    }
    public static function templates(): array { return WechatTemplate::withoutGlobalScope()->order('id desc')->select()->toArray(); }
    public static function uploadTemplateForArtifact(int $artifactId, int $draftId, string $description = ''): array { return self::uploadTemplate($artifactId, $draftId, $description); }
    /**
     * Account data used by platform and tenant management pages.
     *
     * Refresh tokens and access tokens are credentials, not account metadata.
     * Keep them out of every list response, including their encrypted forms.
     */
    public static function authorizers(?int $tenantId = null): array
    {
        $query = WechatAuthorizer::withoutGlobalScope()
            ->field([
                'id',
                'tenant_id',
                'authorizer_appid',
                'authorizer_type',
                'authorizer_name',
                'principal_name',
                'head_img',
                'func_info',
                'authorization_status',
                'unbind_time',
                'last_sync_time',
                'create_time',
                'update_time',
            ]);
        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }
        return $query->order('id desc')->select()->toArray();
    }

    /**
     * Use the effective open-platform account for tenant-scoped operations.
     * Direct-mode callers keep using their existing credentials and services.
     */
    public static function authorizerRequest(int $tenantId, string $authorizerType, string $path, array $payload, string $apiName): array
    {
        $authorizer = WechatAuthorizer::withoutGlobalScope()
            ->where([
                'tenant_id' => $tenantId,
                'authorizer_type' => $authorizerType,
                'authorization_status' => 1,
            ])
            ->order('id desc')
            ->findOrEmpty();
        if ($authorizer->isEmpty()) {
            throw new \RuntimeException('当前租户未授权对应微信账号');
        }

        return self::request(
            $path,
            $payload,
            $apiName,
            ['access_token' => self::authorizerToken((int)$authorizer['id'])],
            $tenantId,
            (int)$authorizer['id']
        );
    }

    public static function saveCredentials(int $tenantId, array $data): array
    {
        $allowed = ['authorizer_id', 'app_secret', 'token', 'encoding_aes_key', 'api_key', 'upload_private_key', 'upload_certificate', 'upload_private_pem'];
        if (!empty($data['authorizer_id'])) {
            $authorizer = WechatAuthorizer::withoutGlobalScope()->where(['id' => (int)$data['authorizer_id'], 'tenant_id' => $tenantId, 'authorization_status' => 1])->findOrEmpty();
            if ($authorizer->isEmpty()) throw new \RuntimeException('授权账号不存在或不属于当前租户');
        }
        $row = WechatCredential::withoutGlobalScope()->where('tenant_id', $tenantId)->findOrEmpty(); $payload = ['tenant_id' => $tenantId, 'update_time' => time()];
        foreach ($allowed as $key) if (array_key_exists($key, $data) && trim((string)$data[$key]) !== '' && !str_contains((string)$data[$key], '*')) $payload[$key] = $key === 'authorizer_id' ? (string)$data[$key] : WechatCredentialService::encrypt((string)$data[$key]);
        if (array_key_exists('upload_mode', $data) && in_array((string)$data['upload_mode'], ['template', 'key'], true)) $payload['upload_mode'] = (string)$data['upload_mode'];
        if (!empty($data['upload_private_key']) && !str_contains((string)$data['upload_private_key'], '*')) {
            $privateKey = trim((string)$data['upload_private_key']);
            if (strlen($privateKey) > 20000 || !str_contains($privateKey, 'PRIVATE KEY') || !openssl_pkey_get_private($privateKey)) {
                throw new \InvalidArgumentException('代码上传密钥格式无效');
            }
            $payload['upload_private_key'] = WechatCredentialService::encrypt($privateKey);
            $payload['verify_status'] = 1;
            $payload['last_verify_time'] = time();
        }
        foreach (['settings_json', 'filing_json'] as $jsonField) {
            if (array_key_exists($jsonField, $data)) {
                $value = is_string($data[$jsonField]) ? json_decode($data[$jsonField], true) : $data[$jsonField];
                if (!is_array($value)) throw new \InvalidArgumentException('设置数据格式错误');
                $payload[$jsonField] = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }
        if ($row->isEmpty()) { $payload['create_time'] = time(); $row = WechatCredential::create($payload); } else $row->save($payload); return self::credentialView($row->toArray());
    }
    public static function credentials(int $tenantId): array { $row = WechatCredential::withoutGlobalScope()->where('tenant_id', $tenantId)->findOrEmpty(); return $row->isEmpty() ? [] : self::credentialView($row->toArray()); }

    /**
     * Upload a manually configured mini program through an isolated
     * miniprogram-ci workspace. The workspace is never shared between tenants
     * and is removed by both the child script and the PHP parent process.
     */
    public static function prepareManualUpload(int $tenantId, array $data = []): array
    {
        self::cleanupStaleManualUploadDirs();
        $state = self::tenantMiniprogramStatus($tenantId);
        if (($state['mode'] ?? '') === 'authorized') throw new \RuntimeException('当前小程序已授权，请使用开放平台模板提交');
        if (($state['mode'] ?? '') !== 'manual') throw new \RuntimeException('请先完成小程序手动配置');
        $artifact = self::latestManualArtifact();
        $version = trim((string)($data['version'] ?? ($artifact['version'] ?? '')));
        if ($version === '' || !preg_match('/^\d+\.\d+\.\d+$/', $version)) throw new \InvalidArgumentException('未找到有效的小程序版本产物，请先完成本地构建');
        $packageManager = (string)($data['package_manager'] ?? 'npm');
        if (!in_array($packageManager, ['npm', 'pnpm', 'yarn'], true)) $packageManager = 'npm';
        $sourceName = trim(str_replace('\\', '/', (string)($data['artifact_dir'] ?? ($artifact['dir'] ?? 'mp-weixin'))), '/');
        if ($sourceName !== 'mp-weixin' && !preg_match('/^(?:runtime\/wechat-artifacts\/\d+\.\d+\.\d+|mp-weixin\.pre-release-\d+\.\d+\.\d+)$/', $sourceName)) throw new \InvalidArgumentException('产物目录无效');
        $source = self::artifactPath($sourceName, $version);
        if (!is_dir($source) || !is_file($source . '/app.json') || !is_file($source . '/project.config.json')) throw new \RuntimeException('小程序产物目录不存在或缺少关键文件');
        $credential = WechatCredential::withoutGlobalScope()->where('tenant_id', $tenantId)->findOrEmpty()->toArray();
        $privateKey = '';
        foreach (['upload_private_key', 'upload_private_pem'] as $key) {
            $privateKey = WechatCredentialService::decrypt($credential[$key] ?? '');
            if ($privateKey !== '') break;
        }
        if ($privateKey === '') throw new \RuntimeException('请先上传小程序代码上传密钥');
        $appId = trim((string)ConfigService::get('mnp_setting', 'app_id', ''));
        if ($appId === '') throw new \RuntimeException('请先配置小程序 AppID');
        $workRoot = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'likeadmin-wechat-upload' . DIRECTORY_SEPARATOR . $tenantId . '-' . bin2hex(random_bytes(12));
        $projectPath = $workRoot . DIRECTORY_SEPARATOR . 'project';
        if (!mkdir($projectPath, 0700, true) && !is_dir($projectPath)) throw new \RuntimeException('无法创建上传临时目录');
        $versionRow = null;
        try {
            self::copyTree($source, $projectPath);
            $runtimeConfig = self::tenantRuntimeConfig($tenantId);
            self::replaceTenantRuntimeMarkers($projectPath, $runtimeConfig);
            $runtimeHash = hash('sha256', json_encode($runtimeConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $keyPath = $workRoot . DIRECTORY_SEPARATOR . 'private.key';
            if (file_put_contents($keyPath, $privateKey, LOCK_EX) === false) throw new \RuntimeException('无法写入临时密钥');
            @chmod($keyPath, 0600);
            $scriptPath = $workRoot . DIRECTORY_SEPARATOR . 'upload.mjs';
            $script = self::manualUploadScript($workRoot, $projectPath, $keyPath, $appId, $version, trim((string)($data['description'] ?? '')));
            if (file_put_contents($scriptPath, $script, LOCK_EX) === false) throw new \RuntimeException('无法生成上传脚本');
            @chmod($scriptPath, 0700);
            $versionRow = WechatMnpVersion::create([
                'tenant_id' => $tenantId,
                'authorizer_id' => 0,
                'template_id' => 0,
                'version' => $version,
                'description' => trim((string)($data['description'] ?? '')),
                'ext_json' => json_encode(['artifact_dir' => $sourceName, 'runtime_config' => $runtimeConfig], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'upload_mode' => 'key',
                'upload_status' => 'running',
                'upload_command' => 'node upload.mjs',
                'api_base_url' => (string)$runtimeConfig['apiBaseUrl'],
                'runtime_config_version' => (string)$runtimeConfig['configVersion'],
                'runtime_config_hash' => $runtimeHash,
                'experience_status' => 'pending',
                'audit_status' => 'none',
                'release_status' => 'none',
                'create_time' => time(),
                'update_time' => time(),
            ]);

            $result = self::executeManualUpload($workRoot, $scriptPath, $packageManager);
            $versionRow->save([
                'upload_status' => $result['success'] ? 'success' : 'failed',
                'update_time' => time(),
            ]);
            return [
                'version_id' => $versionRow->id,
                'version' => $version,
                'upload_status' => $result['success'] ? 'success' : 'failed',
                'output' => $result['output'],
                'timed_out' => $result['timed_out'],
                'install_hint' => $result['install_hint'],
            ];
        } catch (\Throwable $e) {
            if ($versionRow !== null && !empty($versionRow->id)) {
                try { $versionRow->save(['upload_status' => 'failed', 'update_time' => time()]); } catch (\Throwable $ignored) {}
            }
            self::removeTree($workRoot);
            throw $e;
        } finally {
            self::removeTree($workRoot);
        }
    }

    private static function executeManualUpload(string $workRoot, string $scriptPath, string $packageManager): array
    {
        $node = self::findNodeBinary();
        $package = self::findPackageManagerBinary($node, $packageManager) ?: self::findPackageManagerBinary($node);
        $modulePaths = self::findNodeModulePaths($node, $package);
        $installHint = self::manualInstallHint((string)($package['name'] ?? $packageManager));
        if ($node === null) {
            return ['success' => false, 'timed_out' => false, 'output' => '服务端未找到 Node.js，请先安装后重试。', 'install_hint' => $installHint];
        }
        $setupOutput = '';
        if ($modulePaths === []) {
            $setup = self::installMiniprogramCi($workRoot, $node, (string)($package['name'] ?? $packageManager));
            $setupOutput = $setup['output'];
            if (!$setup['success']) {
                return ['success' => false, 'timed_out' => false, 'output' => $setupOutput, 'install_hint' => $installHint];
            }
            $modulePaths = [$workRoot . DIRECTORY_SEPARATOR . 'node_modules'];
        }

        $process = null;
        $closed = false;
        register_shutdown_function(static function () use (&$process, &$closed, $workRoot): void {
            if (is_resource($process)) {
                @proc_terminate($process, 15);
                @proc_close($process);
                $process = null;
            }
            if (!$closed) self::removeTree($workRoot);
        });

        $pipes = [];
        $descriptor = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $baseEnv = getenv();
        if (!is_array($baseEnv)) $baseEnv = [];
        $env = array_merge($baseEnv, [
            'PATH' => dirname($node) . PATH_SEPARATOR . (string)(getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'),
            'NODE_PATH' => implode(PATH_SEPARATOR, $modulePaths),
            'TMPDIR' => rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR),
        ]);
        $process = @proc_open([$node, $scriptPath], $descriptor, $pipes, $workRoot, $env, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            return ['success' => false, 'timed_out' => false, 'output' => '无法启动 Node.js 上传进程。', 'install_hint' => $installHint];
        }
        fclose($pipes[0]);
        foreach ([1, 2] as $index) stream_set_blocking($pipes[$index], false);
        $stdout = '';
        $stderr = '';
        $timedOut = false;
        $deadline = microtime(true) + 180;
        while (true) {
            $status = proc_get_status($process);
            self::appendProcessOutput($pipes[1], $stdout);
            self::appendProcessOutput($pipes[2], $stderr);
            if (!$status['running']) break;
            if (microtime(true) >= $deadline) {
                $timedOut = true;
                @proc_terminate($process, 15);
                usleep(500000);
                if (proc_get_status($process)['running'] ?? false) @proc_terminate($process, 9);
                break;
            }
            usleep(100000);
        }
        foreach ([1, 2] as $index) {
            stream_set_blocking($pipes[$index], true);
            if ($index === 1) self::appendProcessOutput($pipes[$index], $stdout);
            else self::appendProcessOutput($pipes[$index], $stderr);
            fclose($pipes[$index]);
        }
        // PHP may return -1 from proc_close after proc_get_status() has already
        // reaped an exited child. Prefer the final status exit code in that case
        // so a completed miniprogram-ci upload is not recorded as failed.
        $exitCode = proc_close($process);
        if ($exitCode === -1 && isset($status['exitcode']) && (int)$status['exitcode'] >= 0) {
            $exitCode = (int)$status['exitcode'];
        }
        $process = null;
        $closed = true;
        $output = self::sanitizeProcessOutput(trim($setupOutput . ($setupOutput !== '' && ($stdout !== '' || $stderr !== '') ? "\n" : '') . $stdout . ($stderr !== '' ? "\n" . $stderr : '')));
        if ($timedOut) $output = '上传进程超过 180 秒，已终止。' . ($output !== '' ? "\n" . $output : '');
        // miniprogram-ci prints this confirmation only after the provider has
        // accepted the upload. Some PHP builds report -1 from proc_close after
        // the child has already been reaped, so use the provider confirmation
        // as the authoritative fallback for that specific exit-code anomaly.
        $providerConfirmed = str_contains($stdout, '微信小程序代码上传成功');
        $success = !$timedOut && ($exitCode === 0 || ($exitCode === -1 && $providerConfirmed));
        return ['success' => $success, 'timed_out' => $timedOut, 'output' => mb_substr($output, 0, 4000), 'install_hint' => $installHint];
    }

    private static function appendProcessOutput($stream, string &$buffer): void
    {
        $chunk = stream_get_contents($stream);
        if ($chunk !== false && $chunk !== '') $buffer = mb_substr($buffer . $chunk, -12000);
    }

    /** Install only miniprogram-ci inside the already isolated upload directory. */
    private static function installMiniprogramCi(string $workRoot, string $node, string $preferredManager = ''): array
    {
        $package = self::findPackageManagerBinary($node, $preferredManager);
        if ($package === null) return ['success' => false, 'output' => '未找到 npm、pnpm 或 yarn，无法自动安装 miniprogram-ci。'];
        $arguments = match ($package['name']) {
            'pnpm' => [$package['path'], 'add', '--ignore-workspace', 'miniprogram-ci'],
            'yarn' => [$package['path'], 'add', '--ignore-workspace', 'miniprogram-ci'],
            default => [$package['path'], 'install', '--no-save', '--no-package-lock', 'miniprogram-ci'],
        };
        $pipes = [];
        $installEnv = getenv();
        if (!is_array($installEnv)) $installEnv = [];
        $installEnv['PATH'] = dirname($node) . PATH_SEPARATOR . (string)(getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin');
        $process = @proc_open($arguments, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $workRoot, $installEnv, ['bypass_shell' => true]);
        if (!is_resource($process)) return ['success' => false, 'output' => '无法启动 miniprogram-ci 自动安装进程。'];
        fclose($pipes[0]);
        foreach ([1, 2] as $index) stream_set_blocking($pipes[$index], false);
        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + 120;
        $timedOut = false;
        while (true) {
            $status = proc_get_status($process);
            self::appendProcessOutput($pipes[1], $stdout);
            self::appendProcessOutput($pipes[2], $stderr);
            if (!$status['running']) break;
            if (microtime(true) >= $deadline) {
                $timedOut = true;
                @proc_terminate($process, 15);
                break;
            }
            usleep(100000);
        }
        foreach ([1, 2] as $index) {
            stream_set_blocking($pipes[$index], true);
            if ($index === 1) self::appendProcessOutput($pipes[$index], $stdout);
            else self::appendProcessOutput($pipes[$index], $stderr);
            fclose($pipes[$index]);
        }
        $code = proc_close($process);
        if ($code === -1 && isset($status['exitcode']) && (int)$status['exitcode'] >= 0) {
            $code = (int)$status['exitcode'];
        }
        $output = self::sanitizeProcessOutput(trim($stdout . ($stderr !== '' ? "\n" . $stderr : '')));
        if ($timedOut) return ['success' => false, 'output' => '自动安装 miniprogram-ci 超时。' . ($output !== '' ? "\n" . $output : '')];
        if ($code !== 0 || !is_dir($workRoot . DIRECTORY_SEPARATOR . 'node_modules' . DIRECTORY_SEPARATOR . 'miniprogram-ci')) {
            return ['success' => false, 'output' => '自动安装 miniprogram-ci 失败。' . ($output !== '' ? "\n" . $output : '')];
        }
        return ['success' => true, 'output' => $output];
    }

    private static function sanitizeProcessOutput(string $output): string
    {
        $output = preg_replace('/-----BEGIN [^-]+-----.*?-----END [^-]+-----/s', '[REDACTED_KEY]', $output) ?: $output;
        return preg_replace('/(access[_-]?token|secret|private[_-]?key|api[_-]?key|password|authorization|cookie)\s*[:=]\s*[^\s,]+/i', '$1: [REDACTED]', $output) ?: $output;
    }

    private static function findNodeBinary(): ?string
    {
        foreach (self::nodeBinaryCandidates() as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) return $candidate;
        }
        return null;
    }

    /** Return ordered Node candidates for PATH, containers, version managers and explicit overrides. */
    private static function nodeBinaryCandidates(): array
    {
        $candidates = [];
        $explicit = [getenv('WECHAT_UPLOAD_NODE'), getenv('NODE_BINARY'), getenv('NODE_BIN')];
        foreach ($explicit as $value) {
            if (!is_string($value) || trim($value) === '') continue;
            $value = rtrim(trim($value), DIRECTORY_SEPARATOR);
            $candidates[] = is_dir($value) ? $value . DIRECTORY_SEPARATOR . 'node' : $value;
        }
        foreach (explode(PATH_SEPARATOR, (string)getenv('PATH')) as $directory) {
            if ($directory !== '') $candidates[] = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'node';
        }
        $patterns = [
            '/usr/local/bin/node', '/usr/bin/node', '/bin/node', '/www/server/nodejs/bin/node',
            '/www/server/nodejs/*/bin/node', '/opt/node*/bin/node', '/opt/*/bin/node',
            '/usr/local/node*/bin/node', '/usr/local/*/bin/node', '/usr/local/opt/node/bin/node',
            '/opt/homebrew/bin/node', '/opt/homebrew/opt/node/bin/node',
            '/root/.nvm/versions/node/*/bin/node', '/root/.volta/bin/node',
            '/root/.local/share/fnm/node-versions/*/installation/bin/node',
            '/root/.asdf/installs/nodejs/*/bin/node', '/root/.asdf/shims/node',
        ];
        $home = rtrim((string)getenv('HOME'), DIRECTORY_SEPARATOR);
        if ($home !== '') {
            $patterns = array_merge($patterns, [
                $home . '/.nvm/versions/node/*/bin/node', $home . '/.volta/bin/node',
                $home . '/.local/share/fnm/node-versions/*/installation/bin/node',
                $home . '/.asdf/installs/nodejs/*/bin/node', $home . '/.asdf/shims/node',
            ]);
        }
        $patterns = array_merge($patterns, [
            '/home/*/.nvm/versions/node/*/bin/node', '/home/*/.volta/bin/node',
            '/home/*/.local/share/fnm/node-versions/*/installation/bin/node',
            '/home/*/.asdf/installs/nodejs/*/bin/node', '/home/*/.asdf/shims/node',
        ]);
        foreach ($patterns as $pattern) {
            if (str_contains($pattern, '*')) foreach (glob($pattern) ?: [] as $candidate) $candidates[] = $candidate;
            else $candidates[] = $pattern;
        }
        return array_values(array_unique(array_filter($candidates, static fn($path) => is_string($path) && trim($path) !== '')));
    }

    private static function procOpenAvailable(): bool
    {
        if (!function_exists('proc_open')) return false;
        $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
        return !in_array('proc_open', $disabled, true);
    }

    private static function findPackageManagerBinary(?string $node = null, string $preferred = ''): ?array
    {
        $names = in_array($preferred, ['npm', 'pnpm', 'yarn'], true) ? [$preferred] : ['npm', 'pnpm', 'yarn'];
        $candidates = [];
        $explicit = trim((string)(getenv('WECHAT_UPLOAD_PACKAGE_MANAGER') ?: ''));
        if ($explicit !== '') {
            if (in_array($explicit, ['npm', 'pnpm', 'yarn'], true)) $names = [$explicit];
            else {
                $explicit = rtrim($explicit, DIRECTORY_SEPARATOR);
                $explicitName = strtolower(basename($explicit));
                $explicitName = in_array($explicitName, ['npm', 'pnpm', 'yarn'], true) ? $explicitName : $names[0];
                $candidates[$explicitName][] = is_dir($explicit) ? $explicit . DIRECTORY_SEPARATOR . $explicitName : $explicit;
                $names = [$explicitName];
            }
        }
        $nodeBin = $node ? dirname($node) : '';
        foreach ($names as $name) {
            if ($nodeBin !== '') $candidates[$name][] = $nodeBin . DIRECTORY_SEPARATOR . $name;
            foreach (explode(PATH_SEPARATOR, (string)getenv('PATH')) as $directory) {
                if ($directory !== '') $candidates[$name][] = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name;
            }
            $patterns = [
                "/usr/local/bin/{$name}", "/usr/bin/{$name}", "/bin/{$name}", "/www/server/nodejs/bin/{$name}",
                "/www/server/nodejs/*/bin/{$name}", "/opt/node*/bin/{$name}", "/opt/*/bin/{$name}",
                "/usr/local/node*/bin/{$name}", "/usr/local/opt/node/bin/{$name}", "/opt/homebrew/bin/{$name}", "/opt/homebrew/opt/node/bin/{$name}", "/root/.nvm/versions/node/*/bin/{$name}", "/root/.volta/bin/{$name}",
                "/root/.local/share/fnm/node-versions/*/installation/bin/{$name}", "/root/.asdf/installs/nodejs/*/bin/{$name}",
            ];
            $home = rtrim((string)getenv('HOME'), DIRECTORY_SEPARATOR);
            if ($home !== '') $patterns = array_merge($patterns, [$home . "/.nvm/versions/node/*/bin/{$name}", $home . "/.volta/bin/{$name}", $home . "/.local/share/fnm/node-versions/*/installation/bin/{$name}", $home . "/.asdf/installs/nodejs/*/bin/{$name}", $home . "/.asdf/shims/{$name}"]);
            $patterns = array_merge($patterns, ["/home/*/.nvm/versions/node/*/bin/{$name}", "/home/*/.volta/bin/{$name}", "/home/*/.local/share/fnm/node-versions/*/installation/bin/{$name}", "/home/*/.asdf/installs/nodejs/*/bin/{$name}", "/home/*/.asdf/shims/{$name}"]);
            foreach ($patterns as $pattern) {
                if (str_contains($pattern, '*')) foreach (glob($pattern) ?: [] as $candidate) $candidates[$name][] = $candidate;
                else $candidates[$name][] = $pattern;
            }
        }
        foreach ($names as $name) {
            foreach (array_values(array_unique($candidates[$name] ?? [])) as $candidate) {
                if (!is_file($candidate) || !is_executable($candidate)) continue;
                // Prefer a manager next to the selected Node binary so npm cannot escape the PHP runtime.
                if ($node !== null && dirname($candidate) === dirname($node)) return ['name' => $name, 'path' => $candidate];
            }
        }
        foreach ($names as $name) foreach (array_values(array_unique($candidates[$name] ?? [])) as $candidate) if (is_file($candidate) && is_executable($candidate)) return ['name' => $name, 'path' => $candidate];
        return null;
    }

    private static function binaryVersion(string $binary): string
    {
        if (!self::procOpenAvailable()) return '无法读取版本';
        $pipes = [];
        $env = getenv();
        if (!is_array($env)) $env = [];
        $env['PATH'] = dirname($binary) . PATH_SEPARATOR . (string)(getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin');
        $process = @proc_open([$binary, '--version'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env, ['bypass_shell' => true]);
        if (!is_resource($process)) return '版本未知';
        $output = trim((string)stream_get_contents($pipes[1]));
        if (isset($pipes[1]) && is_resource($pipes[1])) fclose($pipes[1]);
        if (isset($pipes[2]) && is_resource($pipes[2])) fclose($pipes[2]);
        proc_close($process);
        return $output !== '' ? mb_substr($output, 0, 80) : '版本未知';
    }

    private static function findNodeModulePaths(?string $node = null, ?array $package = null): array
    {
        $paths = [];
        foreach (['WECHAT_UPLOAD_NODE_PATH', 'NODE_PATH', 'MINIPROGRAM_CI_PATH'] as $name) {
            foreach (explode(PATH_SEPARATOR, (string)getenv($name)) as $path) {
                $path = rtrim(trim($path), DIRECTORY_SEPARATOR);
                if ($path === '') continue;
                $paths[] = basename($path) === 'miniprogram-ci' ? dirname($path) : $path;
            }
        }
        $home = rtrim((string)getenv('HOME'), DIRECTORY_SEPARATOR);
        foreach ([$node, $package['path'] ?? null] as $binary) if (is_string($binary) && $binary !== '') {
            $prefix = dirname(dirname($binary));
            $paths[] = $prefix . '/lib/node_modules';
            $paths[] = $prefix . '/node_modules';
        }
        $fixed = [root_path() . 'node_modules', root_path() . 'server/node_modules', '/usr/local/lib/node_modules', '/usr/lib/node_modules', '/www/server/nodejs/node_modules', '/opt/node_modules', $home !== '' ? $home . '/.npm-global/lib/node_modules' : '', $home !== '' ? $home . '/.local/lib/node_modules' : ''];
        foreach ($fixed as $path) if ($path !== '' && is_dir($path)) $paths[] = $path;
        $patterns = ['/www/server/nodejs/*/lib/node_modules', '/opt/node*/lib/node_modules', '/opt/*/lib/node_modules', '/root/.nvm/versions/node/*/lib/node_modules', '/root/.volta/tools/image/node/*/lib/node_modules', '/root/.asdf/installs/nodejs/*/lib/node_modules', '/root/.local/share/pnpm/global/*/node_modules', '/root/.config/yarn/global/node_modules', '/home/*/.nvm/versions/node/*/lib/node_modules', '/home/*/.volta/tools/image/node/*/lib/node_modules', '/home/*/.asdf/installs/nodejs/*/lib/node_modules', '/home/*/.local/share/pnpm/global/*/node_modules', '/home/*/.config/yarn/global/node_modules'];
        if ($home !== '') $patterns = array_merge($patterns, [$home . '/.nvm/versions/node/*/lib/node_modules', $home . '/.volta/tools/image/node/*/lib/node_modules', $home . '/.asdf/installs/nodejs/*/lib/node_modules', $home . '/.local/share/pnpm/global/*/node_modules', $home . '/.config/yarn/global/node_modules']);
        foreach ($patterns as $pattern) foreach (glob($pattern) ?: [] as $path) if (is_dir($path)) $paths[] = $path;
        $paths = array_values(array_unique($paths));
        foreach ($paths as $path) if (is_dir($path . '/miniprogram-ci')) return $paths;
        return [];
    }

    private static function manualInstallHint(string $packageManager): string
    {
        return match ($packageManager) {
            'pnpm' => 'pnpm add -g miniprogram-ci',
            'yarn' => 'yarn global add miniprogram-ci',
            default => 'npm install -g miniprogram-ci',
        };
    }

    private static function manualUploadScript(string $workRoot, string $projectPath, string $keyPath, string $appId, string $version, string $description): string
    {
        return "import fs from 'node:fs';\nimport path from 'node:path';\nimport { createRequire } from 'node:module';\n\nconst workRoot = " . json_encode($workRoot, JSON_UNESCAPED_SLASHES) . ";\nconst projectPath = " . json_encode($projectPath, JSON_UNESCAPED_SLASHES) . ";\nconst privateKeyPath = " . json_encode($keyPath, JSON_UNESCAPED_SLASHES) . ";\nconst appid = " . json_encode($appId, JSON_UNESCAPED_SLASHES) . ";\nconst version = " . json_encode($version, JSON_UNESCAPED_SLASHES) . ";\nconst desc = " . json_encode($description, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ";\nlet cleaned = false;\nconst cleanup = () => { if (cleaned) return; cleaned = true; try { fs.rmSync(workRoot, { recursive: true, force: true }); } catch (error) { console.error('临时目录清理失败'); } };\nprocess.on('SIGINT', () => { cleanup(); process.exit(130); });\nprocess.on('SIGTERM', () => { cleanup(); process.exit(143); });\ntry {\n  const require = createRequire(import.meta.url);\n  let ci;\n  try { ci = require('miniprogram-ci'); } catch {}\n  if (!ci) {\n    const modulePaths = (process.env.NODE_PATH || '').split(path.delimiter).filter(Boolean);\n    for (const moduleRoot of modulePaths) {\n      try { ci = require(path.join(moduleRoot, 'miniprogram-ci')); break; } catch {}\n      try { ci = require(require.resolve('miniprogram-ci', { paths: [path.dirname(moduleRoot)] })); break; } catch {}\n    }\n  }\n  if (!ci) throw new Error('未找到 miniprogram-ci，请先安装后重试');\n  const project = new ci.Project({ appid, type: 'miniProgram', projectPath, privateKeyPath, ignores: ['node_modules'] });\n  await ci.upload({ project, version, desc });\n  console.log('微信小程序代码上传成功');\n} catch (error) {\n  console.error('微信小程序代码上传失败：' + (error?.message || error));\n  process.exitCode = 1;\n} finally {\n  cleanup();\n}\n";
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) { if (is_file($path)) @unlink($path); return; }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        @rmdir($path);
    }

    private static function cleanupStaleManualUploadDirs(): void
    {
        $root = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'likeadmin-wechat-upload';
        if (!is_dir($root)) return;
        foreach (scandir($root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = $root . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path) && (int)@filemtime($path) < time() - 3600) self::removeTree($path);
        }
    }

    /** Resolve the tenant's configured host for the package runtime. */
    private static function tenantRuntimeConfig(int $tenantId): array
    {
        $domain = '';
        $credential = WechatCredential::withoutGlobalScope()->where('tenant_id', $tenantId)->findOrEmpty()->toArray();
        $settings = json_decode((string)($credential['settings_json'] ?? ''), true);
        $configuredDomain = is_array($settings) ? trim((string)($settings['business_domain'] ?? '')) : '';
        if ($configuredDomain !== '') {
            $domain = preg_match('#^https?://#i', $configuredDomain) ? $configuredDomain : 'https://' . $configuredDomain;
        }
        try {
            if ($domain === '') $domain = (string)request()->domain();
        } catch (\Throwable $ignored) {
            $domain = '';
        }
        if ($domain === '') {
            $host = trim((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
            if ($host !== '') {
                $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
                $domain = $scheme . '://' . $host;
            }
        }
        $domain = rtrim($domain, '/') . '/';
        if (!preg_match('#^https?://[^/]+/$#i', $domain)) {
            throw new \RuntimeException('当前租户域名无效，请先完成域名配置');
        }
        $config = [
            'apiBaseUrl' => $domain,
            'tenantId' => (string)$tenantId,
            'tenant_id' => $tenantId,
            'configVersion' => (string)time(),
        ];
        return $config;
    }

    private static function replaceTenantRuntimeMarkers(string $projectPath, array $config): void
    {
        $path = $projectPath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'runtime.js';
        if (!is_file($path)) {
            throw new \RuntimeException('小程序产物缺少 config/runtime.js，无法注入租户配置');
        }
        $contents = (string)file_get_contents($path);
        if (!str_contains($contents, '__TENANT_API_BASE_URL__')) {
            throw new \RuntimeException('小程序产物未包含租户配置占位标记，请重新构建后再上传');
        }
        $replacements = [
            '__TENANT_API_BASE_URL__' => (string)$config['apiBaseUrl'],
            '__TENANT_ID__' => (string)$config['tenantId'],
            '__TENANT_CONFIG_VERSION__' => (string)$config['configVersion'],
        ];
        $updated = str_replace(array_keys($replacements), array_values($replacements), $contents);
        foreach (array_keys($replacements) as $marker) {
            if (str_contains($updated, $marker)) {
                throw new \RuntimeException('小程序租户配置注入失败');
            }
        }
        if (file_put_contents($path, $updated, LOCK_EX) === false) {
            throw new \RuntimeException('无法保存租户运行时配置');
        }
    }

    private static function credentialView(array $row): array
    {
        foreach (array_merge(self::SECRET_FIELDS, ['api_key']) as $key) if (isset($row[$key])) $row[$key] = WechatCredentialService::mask(WechatCredentialService::decrypt($row[$key]));
        foreach (['settings_json', 'filing_json'] as $key) $row[$key] = json_decode((string)($row[$key] ?? ''), true) ?: [];
        unset($row['id']);
        return $row;
    }

    /** Effective tenant mode for the mini-program management page. */
    public static function tenantMiniprogramStatus(int $tenantId): array
    {
        $authorized = WechatAuthorizer::withoutGlobalScope()->where(['tenant_id' => $tenantId, 'authorizer_type' => 'miniprogram', 'authorization_status' => 1])->order('id desc')->findOrEmpty();
        $appId = trim((string)ConfigService::get('mnp_setting', 'app_id', ''));
        $appSecret = trim((string)ConfigService::get('mnp_setting', 'app_secret', ''));
        $credentials = WechatCredential::withoutGlobalScope()->where('tenant_id', $tenantId)->findOrEmpty()->toArray();
        $hasKey = false;
        foreach (['upload_private_key', 'upload_private_pem'] as $key) if (!empty($credentials[$key]) && WechatCredentialService::decrypt($credentials[$key]) !== '') $hasKey = true;
        $hasManual = $appId !== '' && $appSecret !== '';
        $account = $authorized->isEmpty() ? null : $authorized->toArray();
        if ($account !== null) {
            $account = array_intersect_key($account, array_flip(['id', 'authorizer_appid', 'authorizer_type', 'authorizer_name', 'principal_name', 'head_img', 'authorization_status']));
        }
        return [
            'mode' => $authorized->isEmpty() ? ($hasManual ? 'manual' : 'unconfigured') : 'authorized',
            'authorized' => $account,
            'manual_configured' => $hasManual,
            'upload_key_configured' => $hasKey,
            'upload_mode' => $credentials['upload_mode'] ?? ($authorized->isEmpty() ? 'key' : 'template'),
            'message' => $authorized->isEmpty() ? ($hasManual ? '当前使用手动配置，小程序代码需使用上传密钥在开发者工具上传。' : '请先完成小程序配置或授权绑定。') : '当前使用开放平台授权，版本提交使用平台模板，无需单独填写小程序密钥。',
        ];
    }

    /**
     * Check the local upload runtime before starting a manual upload.
     * The check is intentionally read-only; the upload request may install
     * miniprogram-ci into its isolated temporary workspace when possible.
     */
    public static function manualUploadEnvironment(int $tenantId): array
    {
        try {
            return self::buildManualUploadEnvironment($tenantId);
        } catch (\Throwable $error) {
            return self::manualUploadEnvironmentFailure($error);
        }
    }

    private static function buildManualUploadEnvironment(int $tenantId): array
    {
        $state = self::tenantMiniprogramStatus($tenantId);
        $node = self::findNodeBinary();
        $package = self::findPackageManagerBinary($node);
        $modules = self::findNodeModulePaths($node, $package);
        $processAvailable = self::procOpenAvailable();
        $credentials = WechatCredential::withoutGlobalScope()->where('tenant_id', $tenantId)->findOrEmpty()->toArray();
        $hasKey = false;
        foreach (['upload_private_key', 'upload_private_pem'] as $key) {
            if (!empty($credentials[$key]) && WechatCredentialService::decrypt($credentials[$key]) !== '') {
                $hasKey = true;
                break;
            }
        }
        $artifact = self::latestManualArtifact();
        $runtimeConfig = null;
        $runtimeConfigError = '';
        try { $runtimeConfig = self::tenantRuntimeConfig($tenantId); } catch (\Throwable $e) { $runtimeConfigError = $e->getMessage(); }
        $autoInstall = $processAvailable && $node !== null && $package !== null && $modules === [];
        $guidance = [];
        if (!$processAvailable) $guidance[] = '在 PHP 配置的禁用函数列表中移除 proc_open，然后重启 PHP 服务。';
        if ($node === null) $guidance[] = '先安装 Node.js 16.1 或更高版本，并确认网站运行用户可以执行 node 和 npm。';
        if ($node !== null && $package === null) $guidance[] = '修复 Node.js 安装后附带的 npm，或安装 pnpm、yarn 中任一包管理器。';
        if ($node !== null && $package !== null && $modules === []) $guidance[] = '环境已具备，点击一键上传时系统会在临时目录自动安装 miniprogram-ci。';
        if ($artifact === null) $guidance[] = '在发布机执行 npm run build:mp-weixin，再执行 npm run release:mp-weixin -- --version x.y.z。';
        if ($runtimeConfig === null) $guidance[] = $runtimeConfigError ?: '请先完善租户小程序业务域名。';
        if ($state['manual_configured'] !== true) $guidance[] = '在基本配置中保存小程序 AppID 和 AppSecret。';
        if (!$hasKey) $guidance[] = '在基本配置中上传微信开发者工具生成的代码上传密钥。';
        $checks = [
            ['key' => 'process', 'label' => '进程权限', 'ok' => $processAvailable, 'detail' => $processAvailable ? '已允许服务端执行上传进程' : 'PHP 已禁用 proc_open，需在服务器 PHP 配置中启用'],
            ['key' => 'node', 'label' => 'Node.js', 'ok' => $node !== null, 'detail' => $node ? self::binaryVersion($node) . ' · ' . $node : '未找到 Node.js，请先安装 Node.js 16.1 或更高版本'],
            ['key' => 'package_manager', 'label' => '包管理器', 'ok' => $package !== null, 'detail' => $package ? $package['name'] . ' ' . self::binaryVersion($package['path']) . ' · ' . $package['path'] : '未找到 npm、pnpm 或 yarn'],
            ['key' => 'miniprogram_ci', 'label' => 'miniprogram-ci', 'ok' => $modules !== [] || $autoInstall, 'detail' => $modules !== [] ? '已安装 · ' . $modules[0] : ($autoInstall ? '上传时自动安装到临时目录' : '无法自动安装，请先准备 Node.js 和包管理器')],
            ['key' => 'artifact', 'label' => '小程序产物', 'ok' => $artifact !== null, 'detail' => $artifact ? $artifact['dir'] . '（' . $artifact['version'] . '）' : '未找到支持租户配置的产物，请重新构建'],
            ['key' => 'tenant_domain', 'label' => '租户域名', 'ok' => $runtimeConfig !== null, 'detail' => $runtimeConfig['apiBaseUrl'] ?? ($runtimeConfigError ?: '当前租户域名无效')],
            ['key' => 'appid', 'label' => '小程序 AppID', 'ok' => $state['manual_configured'] === true, 'detail' => $state['manual_configured'] ? '已配置' : '请先完成小程序基本配置'],
            ['key' => 'upload_key', 'label' => '代码上传密钥', 'ok' => $hasKey, 'detail' => $hasKey ? '已配置' : '请先上传微信开发者工具生成的私钥'],
        ];
        $ready = $processAvailable && $state['mode'] === 'manual' && $artifact !== null && $runtimeConfig !== null && $state['manual_configured'] === true && $hasKey && $node !== null && $package !== null;
        return [
            'ready' => $ready,
            'runtime' => [
                'type' => is_file('/.dockerenv') ? 'docker' : 'host',
                'label' => is_file('/.dockerenv') ? '当前 PHP/Docker 容器' : '当前 PHP 服务器',
                'node_scope' => '上传进程必须在此运行环境内可执行，宿主机 Node 不会自动共享给容器',
                'node_path' => $node,
                'package_manager_path' => $package['path'] ?? null,
                'module_paths' => $modules,
                'search_scope' => 'PATH、显式环境变量、宝塔 Node、/usr、/opt、nvm、Volta、fnm、asdf 及常见全局模块目录',
            ],
            'checks' => $checks,
            'artifact' => $artifact,
            'runtime_config' => $runtimeConfig,
            'install_command' => $node === null ? '安装 Node.js 16.1+ 后执行：npm install -g miniprogram-ci' : self::manualInstallHint($package['name'] ?? 'npm'),
            'upload_command' => 'node upload.mjs',
            'guidance' => $guidance,
            'diagnostics' => [],
            'message' => $ready ? '环境已就绪，点击上传即可由服务端自动执行。' : '请根据检测结果补齐环境后再上传。',
        ];
    }

    /**
     * Keep environment-check failures actionable without returning an HTTP 500
     * to the tenant console. Diagnostic values are deliberately sanitized.
     */
    private static function manualUploadEnvironmentFailure(\Throwable $error): array
    {
        $reportId = 'wechat-env-' . date('YmdHis') . '-' . substr(hash('sha256', uniqid('', true)), 0, 8);
        $detail = self::sanitizeProcessOutput(trim((string)$error->getMessage()));
        if ($detail === '') $detail = '检测过程未返回具体错误信息。';
        $detail = mb_substr($detail, 0, 1200);
        $class = get_class($error);
        $class = substr($class, (int)strrpos('\\' . $class, '\\') + 1);
        $location = basename($error->getFile()) . ':' . $error->getLine();

        try {
            \think\facade\Log::error('微信小程序上传环境检测异常', [
                'report_id' => $reportId,
                'exception' => $class,
                'message' => $detail,
                'location' => $location,
            ]);
        } catch (\Throwable $ignored) {
            // A diagnostics response must not depend on the logger being available.
        }

        return [
            'ready' => false,
            'artifact' => null,
            'checks' => [[
                'key' => 'environment_check',
                'label' => '环境检测服务',
                'ok' => false,
                'detail' => '检测执行异常，请展开下方检测错误查看详情。',
            ]],
            'runtime' => null,
            'runtime_config' => null,
            'install_command' => 'npm install -g miniprogram-ci',
            'upload_command' => 'node upload.mjs',
            'guidance' => ['根据检测错误修复环境后，点击重新检测。', '如需人工排查，请提供检测编号。'],
            'diagnostics' => [
                ['key' => 'report_id', 'label' => '检测编号', 'value' => $reportId],
                ['key' => 'stage', 'label' => '执行阶段', 'value' => '环境检测'],
                ['key' => 'error', 'label' => '错误信息', 'value' => $detail],
                ['key' => 'exception', 'label' => '错误类型', 'value' => $class],
                ['key' => 'location', 'label' => '错误位置', 'value' => $location],
            ],
            'message' => '环境检测执行异常，请查看检测错误后重新检测。',
        ];
    }

    private static function latestManualArtifact(): ?array
    {
        $formalMetadataPath = root_path() . 'public/mp-weixin/.artifact.meta.json';
        $formalMetadata = is_file($formalMetadataPath) ? json_decode((string)file_get_contents($formalMetadataPath), true) : [];
        $formalVersion = is_array($formalMetadata) ? (string)($formalMetadata['version'] ?? '') : '';
        $promotedRows = WechatArtifact::withoutGlobalScope()->where('promoted', 1)->where('verify_status', 1)->order('update_time desc')->select();
        $row = null;
        foreach ($promotedRows as $candidate) {
            if ($formalVersion !== '' && (string)$candidate['version'] === $formalVersion) { $row = $candidate; break; }
            if ($formalVersion === '' && $row === null) $row = $candidate;
        }
        if ($row === null) $row = WechatArtifact::withoutGlobalScope()->findOrEmpty(0);
        if ($row->isEmpty()) {
            $version = $formalVersion;
            if (preg_match('/^\d+\.\d+\.\d+$/', $version)) {
                $row = WechatArtifact::withoutGlobalScope()->where('version', $version)->findOrEmpty();
                if ($row->isEmpty()) return null;
            }
        }
        if ($row->isEmpty()) return null;
        $version = (string)$row['version'];
        $directory = self::artifactPath((string)$row['artifact_dir'], $version);
        if (!is_file($directory . '/app.json') || !is_file($directory . '/project.config.json') || !is_file($directory . '/config/runtime.js')) return null;
        $runtimeSource = (string)file_get_contents($directory . '/config/runtime.js');
        if (!str_contains($runtimeSource, '__TENANT_API_BASE_URL__') || !str_contains($runtimeSource, '__TENANT_ID__')) return null;
        $manifest = json_decode((string)$row['sha256_manifest'], true);
        if (!is_array($manifest) || self::fileManifest($directory) !== $manifest) return null;
        return ['id' => (int)$row['id'], 'version' => $version, 'dir' => (string)$row['artifact_dir']];
    }

    public static function bindAuthorizer(int $tenantId, string $appid, string $type, array $profile = []): array
    {
        if ($appid === '' || !in_array($type, ['official', 'miniprogram'], true)) throw new \InvalidArgumentException('授权账号参数错误'); $existing = WechatAuthorizer::withoutGlobalScope()->where('authorizer_appid', $appid)->findOrEmpty(); if (!$existing->isEmpty() && (int)$existing['tenant_id'] !== $tenantId) throw new \RuntimeException('该账号已绑定其他租户');
        $active = WechatAuthorizer::withoutGlobalScope()->where(['tenant_id' => $tenantId, 'authorizer_type' => $type, 'authorization_status' => 1])->select(); foreach ($active as $item) if ((int)$item['id'] !== (int)($existing['id'] ?? 0)) $item->save(['authorization_status' => 0, 'unbind_time' => time(), 'update_time' => time()]);
        $payload = ['tenant_id' => $tenantId, 'authorizer_appid' => $appid, 'authorizer_type' => $type, 'authorization_status' => 1, 'last_sync_time' => time(), 'update_time' => time()]; foreach (['name' => 'authorizer_name', 'principal_name' => 'principal_name', 'head_img' => 'head_img'] as $src => $dst) if (!empty($profile[$src])) $payload[$dst] = (string)$profile[$src]; if (array_key_exists('func_info', $profile)) $payload['func_info'] = json_encode($profile['func_info'], JSON_UNESCAPED_UNICODE); if (!empty($profile['refresh_token'])) $payload['authorizer_refresh_token_ciphertext'] = WechatCredentialService::encrypt((string)$profile['refresh_token']);
        if ($existing->isEmpty()) { $payload['create_time'] = time(); $existing = WechatAuthorizer::create($payload); } else $existing->save($payload); return $existing->toArray();
    }
    public static function unbindAuthorizer(int $tenantId, int $id): bool { $row = WechatAuthorizer::withoutGlobalScope()->where(['id' => $id, 'tenant_id' => $tenantId])->findOrEmpty(); if ($row->isEmpty()) throw new \RuntimeException('授权账号不存在'); $row->save(['authorization_status' => 0, 'unbind_time' => time(), 'update_time' => time()]); return true; }
    public static function createVersion(int $tenantId, array $data): array { foreach (['authorizer_id', 'template_id', 'version'] as $key) if (empty($data[$key])) throw new \InvalidArgumentException('缺少' . $key); if (!preg_match('/^\d+\.\d+\.\d+$/', (string)$data['version'])) throw new \InvalidArgumentException('版本号格式错误'); $authorizer = WechatAuthorizer::withoutGlobalScope()->where(['id' => (int)$data['authorizer_id'], 'tenant_id' => $tenantId, 'authorizer_type' => 'miniprogram', 'authorization_status' => 1])->findOrEmpty(); if ($authorizer->isEmpty()) throw new \RuntimeException('授权小程序不存在'); $template = WechatTemplate::withoutGlobalScope()->where(['id' => (int)$data['template_id'], 'upload_status' => 'success'])->findOrEmpty(); if ($template->isEmpty() || (string)$template['template_id'] === '') throw new \RuntimeException('模板不存在或未上传成功'); $runtimeConfig = self::tenantRuntimeConfig($tenantId); $extJson = is_array($data['ext_json'] ?? null) ? $data['ext_json'] : []; $extJson['runtime_config'] = $runtimeConfig; $runtimeHash = hash('sha256', json_encode($runtimeConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); return WechatMnpVersion::create(['tenant_id' => $tenantId, 'authorizer_id' => (int)$data['authorizer_id'], 'template_id' => (int)$data['template_id'], 'version' => (string)$data['version'], 'description' => (string)($data['description'] ?? ''), 'ext_json' => json_encode($extJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'upload_mode' => 'template', 'upload_status' => 'success', 'runtime_config_hash' => $runtimeHash, 'api_base_url' => (string)$runtimeConfig['apiBaseUrl'], 'runtime_config_version' => (string)$runtimeConfig['configVersion'], 'experience_status' => 'pending', 'audit_status' => 'none', 'release_status' => 'none', 'create_time' => time(), 'update_time' => time()])->toArray(); }
    private static function templateExtJson(WechatMnpVersion $row, WechatAuthorizer $authorizer): array
    {
        $data = json_decode((string)$row['ext_json'], true);
        $data = is_array($data) ? $data : [];
        $runtimeConfig = $data['runtime_config'] ?? null;
        if (!is_array($runtimeConfig)) {
            $runtimeConfig = self::tenantRuntimeConfig((int)$row['tenant_id']);
        }
        return [
            'extEnable' => true,
            'extAppid' => (string)$authorizer['authorizer_appid'],
            'ext' => ['runtime_config' => $runtimeConfig],
        ];
    }
    public static function versions(int $tenantId): array { return WechatMnpVersion::withoutGlobalScope()->where('tenant_id', $tenantId)->order('id desc')->select()->toArray(); }
    public static function reviews(int $tenantId): array { $ids = WechatMnpVersion::withoutGlobalScope()->where('tenant_id', $tenantId)->column('id'); return $ids ? WechatMnpReview::withoutGlobalScope()->whereIn('version_id', $ids)->order('id desc')->select()->toArray() : []; }
    public static function transitionVersion(int $tenantId, int $id, string $action, array $extra = []): array { $row = WechatMnpVersion::withoutGlobalScope()->where(['id' => $id, 'tenant_id' => $tenantId])->findOrEmpty(); if ($row->isEmpty()) throw new \RuntimeException('版本不存在'); $maps = ['experience' => ['field' => 'experience_status', 'states' => ['pending' => 'running', 'running' => 'success', 'success' => 'success']], 'audit' => ['field' => 'audit_status', 'states' => ['none' => 'pending', 'pending' => 'approved', 'approved' => 'approved']], 'release' => ['field' => 'release_status', 'states' => ['none' => 'running', 'running' => 'released', 'released' => 'released']], 'rollback' => ['field' => 'release_status', 'states' => ['released' => 'rollbacking', 'rollbacking' => 'rolled_back']]]; if (!isset($maps[$action])) throw new \InvalidArgumentException('不支持的操作'); $field = $maps[$action]['field']; $next = $maps[$action]['states'][(string)$row[$field]] ?? null; if ($next === null) throw new \RuntimeException('当前状态不允许此操作'); $row->save(array_merge([$field => $next, 'update_time' => time()], $extra)); if ($action === 'audit') WechatMnpReview::withoutGlobalScope()->create(['version_id' => $id, 'audit_status' => $next, 'reason' => '', 'response_summary' => '', 'submit_time' => time(), 'create_time' => time()]); return $row->toArray(); }
    public static function apiLogs(): array { return WechatApiLog::withoutGlobalScope()->order('id desc')->limit(200)->select()->toArray(); }
    public static function promote(int $id): bool
    {
        $lock = SubmitLockService::acquire('wechat.artifact.promote.' . $id, 0, 0);
        try {
            $artifact = WechatArtifact::withoutGlobalScope()->findOrEmpty($id);
            if ($artifact->isEmpty() || (int)$artifact['verify_status'] !== 1) throw new \RuntimeException('产物未通过校验');
            $source = self::artifactPath((string)$artifact['artifact_dir'], (string)$artifact['version']); $target = root_path() . 'public/mp-weixin';
            if (!is_dir($source)) throw new \RuntimeException('产物目录无效');
            $expected = json_decode((string)$artifact['sha256_manifest'], true);
            if (!is_array($expected) || self::fileManifest($source) !== $expected) throw new \RuntimeException('产物校验失败');
            self::replaceFormalArtifact($source, $target, $expected);
            WechatArtifact::withoutGlobalScope()->where('id', '>', 0)->update(['promoted' => 0, 'update_time' => time()]);
            $artifact->save(['promoted' => 1, 'update_time' => time()]); return true;
        } finally { SubmitLockService::release($lock); }
    }
    private static function replaceFormalArtifact(string $source, string $target, array $expected): void
    {
        $parent = dirname($target);
        $stage = $parent . '/.mp-weixin.stage-' . bin2hex(random_bytes(8));
        $backup = $parent . '/.mp-weixin.backup-' . bin2hex(random_bytes(8));
        if (!mkdir($stage, 0755, true)) throw new \RuntimeException('无法创建正式产物暂存目录');
        try {
            self::copyTree($source, $stage);
            if (!is_file($source . '/.artifact.meta.json') || !copy($source . '/.artifact.meta.json', $stage . '/.artifact.meta.json')) throw new \RuntimeException('无法复制产物元数据');
            if (self::fileManifest($stage) !== $expected) throw new \RuntimeException('正式产物暂存校验失败');
            if (is_dir($target) && !rename($target, $backup)) throw new \RuntimeException('无法备份当前正式产物');
            if (!rename($stage, $target)) {
                if (is_dir($backup)) @rename($backup, $target);
                throw new \RuntimeException('无法切换正式产物');
            }
            if (is_dir($backup)) self::removeTree($backup);
        } catch (\Throwable $e) {
            if (is_dir($stage)) self::removeTree($stage);
            if (is_dir($backup) && !is_dir($target)) @rename($backup, $target);
            throw $e;
        }
    }
    private static function copyTree(string $source, string $target): void { foreach (scandir($source) ?: [] as $item) { if ($item === '.' || $item === '..' || $item === '.artifact.meta.json') continue; $src = $source . '/' . $item; $dst = $target . '/' . $item; if (is_dir($src)) { if (!is_dir($dst)) mkdir($dst, 0755, true); self::copyTree($src, $dst); } else copy($src, $dst); } }
}
