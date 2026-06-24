<?php

namespace app\common\service\update;

use app\common\model\update\UpdateLicense;
use RuntimeException;

class UpdateLicenseService
{
    public function info(): array
    {
        $license = $this->latestLicense();
        $payload = $license ? $this->normalizePayload((array)($license['license_json']['payload'] ?? $license['license_json'] ?? [])) : [];
        return [
            'status' => $license ? $this->status($license->toArray(), $payload) : 'not_imported',
            'license' => $license ? $license->toArray() : [],
            'payload' => $payload,
            'machine' => $this->machineCode(),
            'source' => UpdateSourceClient::getSource(),
        ];
    }

    public function machineCode(): array
    {
        $domain = $this->normalizeDomain(request()->host(true));
        $fingerprint = $this->machineFingerprint($domain);
        return [
            'product_code' => UpdateSourceClient::PRODUCT_CODE,
            'domain' => $domain,
            'machine_fingerprint_hash' => $fingerprint['hash'],
            'machine_code' => base64_encode(json_encode([
                'product_code' => UpdateSourceClient::PRODUCT_CODE,
                'domain' => $domain,
                'machine_fingerprint_hash' => $fingerprint['hash'],
                'core_version' => UpdateSourceClient::currentCoreVersion(),
                'generated_at' => time(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'environment' => $fingerprint['public_environment'],
        ];
    }

    public function import(string $filePath): array
    {
        if (!is_file($filePath)) {
            throw new RuntimeException('授权文件不存在');
        }
        $content = trim((string)file_get_contents($filePath));
        $envelope = json_decode($content, true);
        if (!is_array($envelope) || !isset($envelope['payload'], $envelope['signature']) || !is_array($envelope['payload'])) {
            throw new RuntimeException('授权文件格式错误');
        }
        $rawPayload = $envelope['payload'];
        $payload = $this->normalizePayload($rawPayload);
        $this->validatePayload($payload);
        $source = UpdateSourceClient::getSource();
        if (empty($source['public_key'])) {
            throw new RuntimeException('请先配置更新源验签公钥');
        }
        if (!$this->verify($rawPayload, (string)$envelope['signature'], (string)$source['public_key'])) {
            throw new RuntimeException('授权文件签名校验失败');
        }
        $now = time();
        UpdateLicense::where('status', 'active')->update(['status' => 'replaced', 'update_time' => $now]);
        $row = UpdateLicense::create([
            'license_id' => (string)$payload['license_id'],
            'product_code' => (string)$payload['product_code'],
            'customer_name' => (string)($payload['customer_name'] ?? ''),
            'domains_json' => $payload['domains'] ?? [],
            'machine_fingerprint_hash' => (string)$payload['machine_fingerprint_hash'],
            'license_json' => $envelope,
            'signature' => (string)$envelope['signature'],
            'file_sha256' => hash_file('sha256', $filePath),
            'status' => 'active',
            'issued_at' => (int)($payload['issued_at'] ?? 0),
            'expires_at' => (int)($payload['expires_at'] ?? 0),
            'update_until' => (int)($payload['update_until'] ?? 0),
            'create_time' => $now,
            'update_time' => $now,
        ]);
        return [
            'status' => $this->status($row->toArray(), $payload),
            'license' => $row->toArray(),
            'payload' => $payload,
        ];
    }

    public function assertSystemUpdateAllowed(string $targetVersion = ''): void
    {
        $license = $this->latestLicense();
        if (!$license) {
            throw new RuntimeException('请先导入授权文件');
        }
        $payload = $this->normalizePayload((array)($license['license_json']['payload'] ?? []));
        $status = $this->status($license->toArray(), $payload);
        if ($status !== 'active') {
            throw new RuntimeException('授权不可用: ' . $status);
        }
        if (!empty($payload['update_until']) && time() > (int)$payload['update_until']) {
            throw new RuntimeException('系统更新权益已过期');
        }
    }

    public function assertAppUpdateAllowed(string $appCode, string $targetVersion = ''): void
    {
        $this->assertPurchasedApp($appCode, $targetVersion);
    }

    private function assertPurchasedApp(string $appCode, string $targetVersion = ''): void
    {
        $license = $this->latestLicense();
        if (!$license) {
            throw new RuntimeException('请先导入授权文件');
        }
        $payload = $this->normalizePayload((array)($license['license_json']['payload'] ?? []));
        $status = $this->status($license->toArray(), $payload);
        if ($status !== 'active') {
            throw new RuntimeException('授权不可用: ' . $status);
        }
        foreach ((array)($payload['apps'] ?? []) as $app) {
            if (($app['app_code'] ?? $app['code'] ?? '') !== $appCode) {
                continue;
            }
            if (!$this->appPayloadIsOpened($app)) {
                throw new RuntimeException('授权未启用该应用');
            }
            $expiresAt = max(
                (int)($app['expires_at'] ?? 0),
                (int)($app['user_expire_time'] ?? 0),
                (int)($app['tenant_expire_time'] ?? 0)
            );
            if ($expiresAt > 0 && time() > $expiresAt) {
                throw new RuntimeException('该应用授权已过期');
            }
            $maxVersion = (string)($app['max_version'] ?? $app['latest_version'] ?? $app['version'] ?? '');
            if ($maxVersion !== '' && $targetVersion !== '' && version_compare($targetVersion, $maxVersion, '>')) {
                throw new RuntimeException('授权不包含该应用版本权益');
            }
            return;
        }
        throw new RuntimeException('授权不包含该应用');
    }

    private function appPayloadIsOpened(array $app): bool
    {
        $isOpened = $app['is_opened'] ?? $app['enabled'] ?? true;
        if (!$isOpened) {
            return false;
        }
        $expiresAt = max(
            (int)($app['expires_at'] ?? 0),
            (int)($app['user_expire_time'] ?? 0),
            (int)($app['tenant_expire_time'] ?? 0)
        );
        return $expiresAt <= 0 || time() <= $expiresAt;
    }

    public function requestContext(): array
    {
        $domain = $this->normalizeDomain(request()->host(true));
        $license = $this->latestLicense();
        return [
            'domain' => $domain,
            'machine_fingerprint_hash' => $this->machineFingerprint($domain)['hash'],
            'license' => $license ? ($license['license_json'] ?: new \stdClass()) : new \stdClass(),
        ];
    }

    private function validatePayload(array $payload): void
    {
        if (($payload['product_code'] ?? '') !== UpdateSourceClient::PRODUCT_CODE) {
            throw new RuntimeException('授权文件产品码不匹配，期望: ' . UpdateSourceClient::PRODUCT_CODE . '，实际: ' . (string)($payload['product_code'] ?? '缺失'));
        }
        if (empty($payload['license_id'])) {
            throw new RuntimeException('授权文件缺少 license_id');
        }
        $domain = $this->normalizeDomain(request()->host(true));
        $domains = array_map([$this, 'normalizeDomain'], (array)($payload['domains'] ?? []));
        if (!in_array($domain, $domains, true)) {
            throw new RuntimeException('授权文件绑定域名不匹配');
        }
        $fingerprint = $this->machineFingerprint($domain);
        if (($payload['machine_fingerprint_hash'] ?? '') !== $fingerprint['hash']) {
            throw new RuntimeException('授权文件绑定机器不匹配');
        }
        if (!empty($payload['expires_at']) && time() > (int)$payload['expires_at']) {
            throw new RuntimeException('授权文件已过期');
        }
    }

    private function normalizePayload(array $payload): array
    {
        $machineCode = $this->decodeMachineCode((string)($payload['machine_code'] ?? ''));
        if (empty($payload['product_code']) && !empty($machineCode['product_code'])) {
            $payload['product_code'] = (string)$machineCode['product_code'];
        }
        if (empty($payload['license_id']) && !empty($payload['license_no'])) {
            $payload['license_id'] = (string)$payload['license_no'];
        }
        if (empty($payload['customer_name'])) {
            $payload['customer_name'] = (string)($payload['customer_name'] ?? $payload['user_id'] ?? $payload['tenant_id'] ?? '');
        }
        if (empty($payload['max_core_version']) && !empty($payload['version'])) {
            $payload['max_core_version'] = (string)$payload['version'];
        }
        if (!isset($payload['apps']) || !is_array($payload['apps'])) {
            $payload['apps'] = [];
        }
        foreach ($payload['apps'] as &$app) {
            if (!is_array($app)) {
                $app = [];
                continue;
            }
            $app['max_version'] = (string)($app['max_version'] ?? $app['latest_version'] ?? $app['version'] ?? '');
            $app['expires_at'] = max(
                (int)($app['expires_at'] ?? 0),
                (int)($app['user_expire_time'] ?? 0),
                (int)($app['tenant_expire_time'] ?? 0)
            );
            $app['enabled'] = (bool)($app['is_opened'] ?? $app['enabled'] ?? true);
        }
        unset($app);
        return $payload;
    }

    private function decodeMachineCode(string $machineCode): array
    {
        if ($machineCode === '') {
            return [];
        }
        $json = base64_decode($machineCode, true);
        if ($json === false) {
            return [];
        }
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    private function verify(array $payload, string $signature, string $publicKey): bool
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $key = openssl_pkey_get_public($publicKey);
        if (!$key) {
            return false;
        }
        return openssl_verify($json, base64_decode($signature), $key, OPENSSL_ALGO_SHA256) === 1;
    }

    private function status(array $license, array $payload): string
    {
        if (($license['status'] ?? '') !== 'active') {
            return (string)($license['status'] ?? 'inactive');
        }
        if (!empty($payload['expires_at']) && time() > (int)$payload['expires_at']) {
            return 'expired';
        }
        $domain = $this->normalizeDomain(request()->host(true));
        $domains = array_map([$this, 'normalizeDomain'], (array)($payload['domains'] ?? []));
        if (!in_array($domain, $domains, true)) {
            return 'domain_mismatch';
        }
        if (($payload['machine_fingerprint_hash'] ?? '') !== $this->machineFingerprint($domain)['hash']) {
            return 'machine_mismatch';
        }
        return 'active';
    }

    private function latestLicense(): ?UpdateLicense
    {
        $row = UpdateLicense::order('id', 'desc')->findOrEmpty();
        return $row->isEmpty() ? null : $row;
    }

    private function machineFingerprint(string $domain): array
    {
        $hostname = gethostname() ?: php_uname('n');
        $data = [
            'product_code' => UpdateSourceClient::PRODUCT_CODE,
            'unique_identification' => (string)env('project.unique_identification', ''),
            'domain' => $domain,
            'root_path_hash' => hash('sha256', root_path()),
            'database_hash' => hash('sha256', (string)config('database.connections.mysql.database')),
            'hostname_hash' => hash('sha256', (string)$hostname),
        ];
        return [
            'hash' => hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'public_environment' => [
                'php_version' => PHP_VERSION,
                'core_version' => UpdateSourceClient::currentCoreVersion(),
                'domain' => $domain,
            ],
        ];
    }

    private function normalizeDomain(string $domain): string
    {
        $host = parse_url($domain, PHP_URL_HOST) ?: $domain;
        return strtolower(trim($host));
    }
}
