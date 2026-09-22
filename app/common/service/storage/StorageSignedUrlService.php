<?php
declare(strict_types=1);

namespace app\common\service\storage;

use OSS\OssClient;
use Qcloud\Cos\Client as QcloudClient;
use Qiniu\Auth as QiniuAuth;
use RuntimeException;

/**
 * Resolves short-lived read URLs for storage engines explicitly configured as
 * private.  The URL is generated from server-side storage metadata only; a
 * browser never supplies a bucket, object key, credential, or expiry.
 *
 * Public storage remains on FileService's existing direct-URL path.  A
 * configured private engine fails closed instead of accidentally exposing an
 * unsigned object URL.
 */
final class StorageSignedUrlService
{
    private const TTL_SECONDS = 300;

    public static function resolve(string $uri, string $scope, string $engine, string $domain, ?int $tenantId = null): ?string
    {
        $engine = strtolower(trim($engine));
        if ($uri === '' || !in_array($engine, ['qiniu', 'aliyun', 'qcloud'], true)) {
            return null;
        }
        $config = StorageConfigService::getStoredFileConfig($tenantId, $scope, $engine);
        $storage = (array)($config['engine'][$engine] ?? []);
        if (!self::privateAccess($storage)) {
            return null;
        }
        $key = ltrim($uri, '/');
        if ($key === '' || str_contains($key, "\0") || str_contains($key, '../')) {
            throw new RuntimeException('PRIVATE_MEDIA_URL_INVALID');
        }
        try {
            return match ($engine) {
                'qiniu' => self::qiniu($storage, $key, $domain),
                'aliyun' => self::aliyun($storage, $key),
                'qcloud' => self::qcloud($storage, $key),
            };
        } catch (\Throwable $error) {
            // Never return an unsigned fallback for a private object.  Keep
            // detailed storage/credential diagnostics on the server only.
            throw new RuntimeException('PRIVATE_MEDIA_URL_UNAVAILABLE');
        }
    }

    private static function privateAccess(array $storage): bool
    {
        return (bool)($storage['private_access'] ?? $storage['private'] ?? false);
    }

    private static function qiniu(array $storage, string $key, string $domain): string
    {
        $base = self::url($domain ?: (string)($storage['domain'] ?? ''), $key);
        return (new QiniuAuth((string)$storage['access_key'], (string)$storage['secret_key']))
            ->privateDownloadUrl($base, self::TTL_SECONDS);
    }

    private static function aliyun(array $storage, string $key): string
    {
        $client = new OssClient((string)$storage['access_key'], (string)$storage['secret_key'], (string)$storage['domain'], true);
        return $client->signUrl((string)$storage['bucket'], $key, self::TTL_SECONDS, OssClient::OSS_HTTP_GET);
    }

    private static function qcloud(array $storage, string $key): string
    {
        $client = new QcloudClient([
            'region' => (string)$storage['region'],
            'credentials' => ['secretId' => (string)$storage['access_key'], 'secretKey' => (string)$storage['secret_key']],
        ]);
        return $client->getObjectUrl((string)$storage['bucket'], $key, '+' . self::TTL_SECONDS . ' seconds');
    }

    private static function url(string $domain, string $key): string
    {
        $domain = rtrim(trim($domain), '/');
        if (!preg_match('#^https?://#i', $domain)) {
            throw new RuntimeException('PRIVATE_MEDIA_URL_UNAVAILABLE');
        }
        return $domain . '/' . $key;
    }
}
