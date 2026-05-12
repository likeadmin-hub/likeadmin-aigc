<?php

namespace app\common\service\update;

use app\common\model\update\UpdateSource;
use RuntimeException;
use think\facade\Db;
use WpOrg\Requests\Requests;

class UpdateSourceClient
{
    public const PRODUCT_CODE = 'likeadmin_aigc_saas';

    public static function getSource(): array
    {
        self::ensureSchema();
        $source = UpdateSource::where('status', 1)->order('id', 'desc')->findOrEmpty();
        if ($source->isEmpty()) {
            return [
                'base_url' => '',
                'license_key' => '',
                'api_key' => '',
                'online_base_url' => '',
                'online_license_key' => '',
                'dev_mode' => 1,
                'ssl_verify' => 0,
                'active_base_url' => '',
                'active_api_key' => '',
                'public_key' => '',
            ];
        }
        $data = $source->toArray();
        $data['dev_mode'] = (int)($data['dev_mode'] ?? 1);
        $data['ssl_verify'] = (int)($data['ssl_verify'] ?? 0);
        $data['online_base_url'] = (string)($data['online_base_url'] ?? '');
        $data['online_license_key'] = (string)($data['online_license_key'] ?? '');
        $data['api_key'] = $data['api_key'] ?? ($data['license_key'] ?? '');
        $data['active_base_url'] = self::activeBaseUrl($data);
        $data['active_api_key'] = self::activeApiKey($data);
        return $data;
    }

    public static function saveSource(array $params): array
    {
        self::ensureSchema();
        $data = [
            'name' => trim((string)($params['name'] ?? '授权系统')),
            'base_url' => rtrim(trim((string)($params['base_url'] ?? '')), '/'),
            'license_key' => trim((string)($params['license_key'] ?? ($params['api_key'] ?? ''))),
            'online_base_url' => rtrim(trim((string)($params['online_base_url'] ?? '')), '/'),
            'online_license_key' => trim((string)($params['online_license_key'] ?? '')),
            'dev_mode' => (int)($params['dev_mode'] ?? 1) === 1 ? 1 : 0,
            'ssl_verify' => (int)($params['ssl_verify'] ?? 0) === 1 ? 1 : 0,
            'public_key' => trim((string)($params['public_key'] ?? '')),
            'status' => (int)($params['status'] ?? 1),
            'update_time' => time(),
        ];
        if ($data['base_url'] === '') {
            throw new RuntimeException('请填写开发模式接口地址');
        }
        if ($data['dev_mode'] === 0 && $data['online_base_url'] === '') {
            throw new RuntimeException('关闭开发模式时请填写线上接口地址');
        }
        if ($data['dev_mode'] === 0 && $data['online_license_key'] === '') {
            throw new RuntimeException('关闭开发模式时请填写线上 API Key');
        }
        $row = UpdateSource::where('id', (int)($params['id'] ?? 0))->findOrEmpty();
        if ($row->isEmpty()) {
            $data['create_time'] = time();
            $row = UpdateSource::create($data);
        } else {
            $row->save($data);
        }
        return self::getSource();
    }

    public function request(string $path, array $payload = []): array
    {
        $source = self::getSource();
        if (empty($source['active_base_url'])) {
            throw new RuntimeException('未配置接口渠道');
        }
        $license = new UpdateLicenseService();
        $context = $license->requestContext();
        $payload = array_merge([
            'product_code' => self::PRODUCT_CODE,
            'core_version' => self::currentCoreVersion(),
            'domain' => $context['domain'],
            'license_key' => $source['active_api_key'] ?? '',
            'machine_fingerprint_hash' => $context['machine_fingerprint_hash'],
            'timestamp' => time(),
            'nonce' => bin2hex(random_bytes(8)),
            'license' => $context['license'],
        ], $payload);
        $url = self::buildUrl((string)$source['active_base_url'], $path);
        $headers = [
            'Content-Type' => 'application/json',
            'X-AIGC-Domain' => (string)$payload['domain'],
            'X-AIGC-Machine-Fingerprint' => (string)$payload['machine_fingerprint_hash'],
        ];
        $apiKey = trim((string)($source['active_api_key'] ?? $source['api_key'] ?? $source['license_key'] ?? ''));
        if ($apiKey !== '') {
            $headers['Authorization'] = 'Bearer ' . $apiKey;
        }
        $response = Requests::post($url, $headers, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), [
            'timeout' => 15,
            'verify' => self::sslVerify($source),
        ]);
        $body = json_decode($response->body, true);
        if (!is_array($body)) {
            $contentType = strtolower((string)($response->headers['content-type'] ?? ''));
            $preview = mb_substr(trim(strip_tags((string)$response->body)), 0, 120);
            if (str_contains($contentType, 'text/html') || str_contains((string)$response->body, '<html')) {
                throw new RuntimeException('更新源响应格式错误：接口渠道返回了 HTML 页面，请确认地址指向服务端 /aigc/v1 接口而不是 PC 前端页面');
            }
            throw new RuntimeException('更新源响应格式错误' . ($preview !== '' ? '：' . $preview : ''));
        }
        if (!empty($source['public_key']) && !self::verifyResponse($body, (string)$source['public_key'])) {
            throw new RuntimeException('更新源响应签名校验失败');
        }
        if ((int)($body['code'] ?? 0) !== 1) {
            $errorCode = (string)($body['data']['error_code'] ?? '');
            $message = (string)($body['msg'] ?? '更新源请求失败');
            throw new RuntimeException($errorCode !== '' ? $message . ' (' . $errorCode . ')' : $message);
        }
        return $body['data'] ?? [];
    }

    public static function path(string $endpoint): string
    {
        return '/aigc/v1/' . ltrim($endpoint, '/');
    }

    private static function activeBaseUrl(array $source): string
    {
        $devMode = (int)($source['dev_mode'] ?? 1) === 1;
        $baseUrl = $devMode ? (string)($source['base_url'] ?? '') : (string)($source['online_base_url'] ?? '');
        return rtrim(trim($baseUrl), '/');
    }

    private static function activeApiKey(array $source): string
    {
        $devMode = (int)($source['dev_mode'] ?? 1) === 1;
        $key = $devMode ? (string)($source['license_key'] ?? '') : (string)($source['online_license_key'] ?? '');
        return trim($key);
    }

    private static function ensureSchema(): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;
        $table = str_replace('`', '``', (new UpdateSource())->db()->getTable());
        $fields = array_column(Db::query('SHOW COLUMNS FROM `' . $table . '`'), 'Field');
        $sql = [];
        if (!in_array('online_base_url', $fields, true)) {
            $sql[] = "ADD COLUMN `online_base_url` varchar(255) NOT NULL DEFAULT '' COMMENT '线上授权系统接口地址' AFTER `license_key`";
        }
        if (!in_array('online_license_key', $fields, true)) {
            $sql[] = "ADD COLUMN `online_license_key` varchar(255) NOT NULL DEFAULT '' COMMENT '线上API Key/授权key' AFTER `online_base_url`";
        }
        if (!in_array('dev_mode', $fields, true)) {
            $sql[] = "ADD COLUMN `dev_mode` tinyint NOT NULL DEFAULT 1 COMMENT '开发模式：1开启 0关闭' AFTER `online_license_key`";
        }
        if (!in_array('ssl_verify', $fields, true)) {
            $sql[] = "ADD COLUMN `ssl_verify` tinyint NOT NULL DEFAULT 0 COMMENT 'SSL证书校验：1开启 0关闭' AFTER `dev_mode`";
        }
        if ($sql) {
            Db::execute('ALTER TABLE `' . $table . '` ' . implode(', ', $sql));
        }
    }

    private static function buildUrl(string $baseUrl, string $path): string
    {
        $baseUrl = rtrim($baseUrl, '/');
        $path = '/' . ltrim($path, '/');
        if (str_ends_with($baseUrl, '/aigc/v1') && str_starts_with($path, '/aigc/v1/')) {
            $path = substr($path, strlen('/aigc/v1'));
        }
        return $baseUrl . $path;
    }

    public static function currentCoreVersion(): string
    {
        $local = function_exists('local_version') ? local_version() : [];
        $version = is_array($local) ? (string)($local['version'] ?? '') : '';
        return $version !== '' ? $version : (string)config('project.version');
    }

    public static function verifyResponse(array $body, string $publicKey): bool
    {
        if (empty($body['signature'])) {
            return false;
        }
        $payload = [
            'code' => $body['code'] ?? null,
            'msg' => $body['msg'] ?? '',
            'data' => $body['data'] ?? [],
            'request_id' => $body['request_id'] ?? '',
            'server_time' => $body['server_time'] ?? 0,
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $key = openssl_pkey_get_public($publicKey);
        if (!$key) {
            return false;
        }
        return openssl_verify($json, base64_decode((string)$body['signature']), $key, OPENSSL_ALGO_SHA256) === 1;
    }

    public static function download(string $url, string $sha256 = '', string $format = 'zip'): array
    {
        if ($url === '') {
            throw new RuntimeException('下载地址为空');
        }
        $dir = runtime_path() . 'update_packages/';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $ext = $format === 'tar.gz' ? 'tar.gz' : pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION);
        $filename = date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . ($ext ?: 'zip');
        $path = $dir . $filename;
        $response = Requests::get($url, [], [
            'timeout' => 60,
            'verify' => self::sslVerify(self::getSource()),
        ]);
        if ((int)$response->status_code !== 200) {
            throw new RuntimeException('下载更新包失败');
        }
        file_put_contents($path, $response->body);
        $hash = hash_file('sha256', $path);
        if ($sha256 !== '' && $hash !== $sha256) {
            @unlink($path);
            throw new RuntimeException('更新包 checksum 校验失败');
        }
        return [
            'path' => $path,
            'sha256' => $hash,
            'size' => filesize($path),
            'format' => $format ?: 'zip',
        ];
    }

    public static function sslVerify(array $source = []): bool
    {
        return (int)($source['ssl_verify'] ?? 0) === 1;
    }
}
