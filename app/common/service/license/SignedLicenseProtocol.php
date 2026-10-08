<?php

declare(strict_types=1);
namespace app\common\service\license;

use RuntimeException;

/** Signed JSON is never normalized before verification. */
class SignedLicenseProtocol
{
    public static function decode(string $json): \stdClass
    {
        $object = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        if (!$object instanceof \stdClass) throw new RuntimeException('LICENSE_INVALID');
        return $object;
    }

    public static function verify(object $object, string $publicKey): void
    {
        if ($publicKey === '') throw new RuntimeException('LICENSE_PUBLIC_KEY_MISSING');
        $signature = base64_decode((string)($object->signature ?? ''), true);
        $signed = clone $object;
        unset($signed->signature);
        if ($signature === false || openssl_verify(self::json($signed), $signature, $publicKey, OPENSSL_ALGO_SHA256) !== 1) {
            throw new RuntimeException('LICENSE_SIGNATURE_INVALID');
        }
    }

    public static function certificate(string $json, string $publicKey): array
    {
        $envelope = self::decode($json);
        if (!($envelope->payload ?? null) instanceof \stdClass || ($envelope->algorithm ?? 'RSA-SHA256') !== 'RSA-SHA256') {
            throw new RuntimeException('LICENSE_INVALID');
        }
        $signed = clone $envelope->payload;
        // A certificate signs its payload, whereas access responses sign the entire envelope.
        $signature = base64_decode((string)($envelope->signature ?? ''), true);
        if ($publicKey === '' || $signature === false || openssl_verify(self::json($signed), $signature, $publicKey, OPENSSL_ALGO_SHA256) !== 1) {
            throw new RuntimeException('LICENSE_SIGNATURE_INVALID');
        }
        return json_decode(self::json($signed), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function commercial(array $payload): bool
    {
        return ($payload['license_type'] ?? '') === 'commercial' && ($payload['is_perpetual'] ?? null) === true
            && isset($payload['expires_at']) && $payload['expires_at'] === 0;
    }

    public static function response(string $json, string $key, array $context, string $nonce, ?int $now = null): array
    {
        $now = $now ?? time();
        $object = self::decode($json);
        self::verify($object, $key);
        $body = json_decode(self::json($object), true, 512, JSON_THROW_ON_ERROR);
        $data = $body['data'] ?? [];
        if (!is_array($data) || ($data['request_nonce'] ?? '') !== $nonce
            || ($data['license_no'] ?? '') !== $context['license_no']
            || ($data['license_version'] ?? null) !== $context['license_version']
            || ($data['machine_fingerprint_hash'] ?? '') !== $context['machine_fingerprint_hash']
            || !is_int($data['issued_at'] ?? null) || !is_int($body['server_time'] ?? null)
            || $data['issued_at'] > $now + 300 || abs($body['server_time'] - $data['issued_at']) > 300) {
            throw new RuntimeException('LICENSE_RESPONSE_MISMATCH');
        }
        if (($body['code'] ?? null) !== 1) {
            if (($data['domain'] ?? '') !== $context['domain'] || empty($data['error_code'])) throw new RuntimeException('LICENSE_RESPONSE_MISMATCH');
            return $body;
        }
        if (($data['protocol_version'] ?? null) !== 1 || ($data['site_status'] ?? '') !== 'active'
            || ($data['license_type'] ?? '') !== $context['license_type']
            || !in_array($context['domain'], (array)($data['domains'] ?? []), true)
            || !is_int($data['expires_at'] ?? null) || !is_int($data['refresh_after'] ?? null)
            || $data['expires_at'] <= $now || $data['expires_at'] <= $data['issued_at']
            || $data['expires_at'] > $data['issued_at'] + 7 * 86400
            || $data['refresh_after'] < $data['issued_at'] || $data['refresh_after'] > $data['expires_at']
            || ($context['license_type'] === 'free' && $context['certificate_expires_at'] > 0 && $data['expires_at'] > $context['certificate_expires_at'])) {
            throw new RuntimeException('LICENSE_RESPONSE_INVALID');
        }
        return $body;
    }

    public static function issuer(array $payload): array
    {
        if (!isset($payload['issuer']) || !is_array($payload['issuer'])) return [];
        $issuer = $payload['issuer'];
        return [
            'platform_name' => (string)($issuer['platform_name'] ?? ''),
            'platform_logo' => self::httpUrl((string)($issuer['platform_logo'] ?? '')),
            'api_base_url' => self::httpUrl((string)($issuer['api_base_url'] ?? '')),
            'copyright' => array_values(array_map(static fn($item) => [
                'key' => (string)($item['text'] ?? ''), 'value' => self::httpUrl((string)($item['url'] ?? '')),
            ], array_filter((array)($issuer['copyright'] ?? []), 'is_array'))),
            'copyright_provided' => array_key_exists('copyright', $issuer) && is_array($issuer['copyright']),
        ];
    }

    public static function httpUrl(string $url): string
    {
        return filter_var($url, FILTER_VALIDATE_URL) && in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true) ? $url : '';
    }

    public static function json($object): string
    {
        return json_encode($object, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
