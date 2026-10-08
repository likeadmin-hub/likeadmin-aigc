<?php

declare(strict_types=1);

namespace app\common\service\update;

use app\common\service\license\SignedLicenseProtocol as Signed;

/** Displaying update service is independent of granting a specific system package. */
class SystemUpdateProtocol
{
    public static function updates(array $payload, array $online = [], ?int $now = null): array
    {
        $now = $now ?? time();
        $mode = (string)($online['update_mode'] ?? $payload['update_mode'] ?? 'legacy');
        if (!in_array($mode, ['annual', 'lifetime', 'tenant_policy', 'legacy'], true)) $mode = 'unknown';
        $until = (int)($online['update_until'] ?? $payload['update_until'] ?? 0);
        $canUpdate = ($online['can_update'] ?? false) === true;
        $state = 'unverified';
        if ($mode === 'annual' && $until <= $now) {
            $canUpdate = false;
            $state = $until > 0 ? 'expired' : 'inactive';
        } elseif ($online !== []) {
            $state = $canUpdate ? 'active' : ($mode === 'tenant_policy' ? 'disabled' : 'inactive');
        } elseif ($mode === 'legacy' && $until > 0 && $until <= $now) {
            $state = 'expired';
        }
        return ['update_mode' => $mode, 'update_until' => $until,
            'update_rights_revision' => (int)($online['update_rights_revision'] ?? $payload['update_rights_revision'] ?? 0),
            'update_state' => $state, 'can_update' => $canUpdate];
    }

    public static function contextKey(array $context, array $source): string
    {
        return hash('sha256', Signed::json([
            $source['active_base_url'] ?? '', hash('sha256', (string)($source['active_api_key'] ?? '')),
            hash('sha256', (string)($source['public_key'] ?? '')), (bool)($source['ssl_verify'] ?? false),
            hash('sha256', (string)($context['raw_certificate'] ?? '')),
            $context['domain'], $context['machine_fingerprint_hash'], $context['ip'] ?? '',
        ]));
    }

    public static function envelope(string $raw, string $key, ?int $freshAt = null): array
    {
        $object = Signed::decode($raw);
        Signed::verify($object, $key);
        $body = json_decode(Signed::json($object), true, 512, JSON_THROW_ON_ERROR);
        if (!is_int($body['server_time'] ?? null) || !is_array($body['data'] ?? null)
            || ($freshAt !== null && abs($body['server_time'] - $freshAt) > 300)) {
            throw new UpdateProtocolException('SYSTEM_RESPONSE_INVALID', '更新源响应时间或数据格式错误');
        }
        return $body;
    }

    public static function package(string $raw, string $key, array $context, string $version,
        string $sha256 = '', string $format = '', ?int $now = null): array
    {
        $now = $now ?? time();
        $body = self::envelope($raw, $key);
        $data = $body['data'];
        if (($data['has_update'] ?? null) === false) {
            throw new UpdateProtocolException('SYSTEM_PACKAGE_UNAVAILABLE', '更新源没有提供此目标版本的可用包，请联系签发方确认下载或重装支持');
        }
        $grant = $data['site_grant'] ?? [];
        if (($body['code'] ?? null) !== 1 || !is_array($grant)
            || ($data['product_code'] ?? '') !== UpdateSourceClient::PRODUCT_CODE
            || ($data['version'] ?? '') !== $version || ($data['target_version'] ?? $version) !== $version
            || ($grant['license_no'] ?? '') !== $context['license_no']
            || ($grant['license_version'] ?? null) !== $context['license_version']
            || !in_array($context['domain'], (array)($grant['domains'] ?? []), true)
            || ($grant['machine_fingerprint_hash'] ?? '') !== $context['machine_fingerprint_hash']
            || !is_int($grant['version_id'] ?? null) || $grant['version_id'] <= 0
            || !is_int($grant['issued_at'] ?? null) || !is_int($grant['expires_at'] ?? null)
            || $grant['issued_at'] > $now + 300 || abs($body['server_time'] - $grant['issued_at']) > 300
            || $grant['expires_at'] <= $grant['issued_at'] || $grant['expires_at'] > $grant['issued_at'] + 3600) {
            throw new UpdateProtocolException('SYSTEM_PACKAGE_GRANT_INVALID', '系统包授权与本站证书、绑定或目标版本不一致');
        }
        if ($grant['expires_at'] <= $now) {
            throw new UpdateProtocolException('SYSTEM_PACKAGE_GRANT_EXPIRED', '系统包安装授权已过期，需要在线重新校验');
        }
        if (!preg_match('/^[a-f0-9]{64}$/D', (string)($data['sha256'] ?? ''))
            || !in_array($data['format'] ?? '', ['zip','tar.gz','tgz','tar'], true)) {
            throw new UpdateProtocolException('SYSTEM_PACKAGE_HASH_MISMATCH', '更新源未提供有效的包摘要和格式');
        }
        if (!empty($data['fallback_url']) && (!preg_match('/^[a-f0-9]{64}$/D', (string)($data['fallback_sha256'] ?? ''))
            || !in_array($data['fallback_format'] ?? '', ['zip','tar.gz','tgz','tar'], true))) {
            throw new UpdateProtocolException('SYSTEM_PACKAGE_HASH_MISMATCH', '备用包缺少有效的签名摘要或格式');
        }
        if ($sha256 !== '') {
            $matches = false;
            foreach ([['sha256', 'format'], ['fallback_sha256', 'fallback_format']] as [$hashField, $formatField]) {
                $expected = (string)($data[$hashField] ?? '');
                if (preg_match('/^[a-f0-9]{64}$/D', $expected)
                    && hash_equals($expected, $sha256) && ($format === '' || ($data[$formatField] ?? '') === $format)) $matches = true;
            }
            if (!$matches) throw new UpdateProtocolException('SYSTEM_PACKAGE_HASH_MISMATCH', '系统包摘要不在本站签名授权范围内');
        }
        return $data;
    }
}
