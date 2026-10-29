<?php
declare(strict_types=1);
namespace app\common\service\distribution;

use app\common\service\FileService;
use app\common\service\open_platform\OpenSafeHttp;
use app\common\service\storage\{StorageConfigService, StorageOperationTimeout};
use Qcloud\Cos\Client;
use RuntimeException;

/** Bounded read adapter for already-authorized managed receipts, never a URL proxy. */
final class PosterRasterService
{
    public const MAX_BYTES = 8388608;
    private const UNAVAILABLE = '图片加载失败，请联系管理员检查托管图片是否可读取';

    public static function read(array $receipt, int $tenantId, float $deadline): array
    {
        if ($tenantId <= 0 || (int)($receipt['tenant_id'] ?? 0) !== $tenantId || (int)($receipt['type'] ?? 0) !== 10) {
            throw new RuntimeException(self::UNAVAILABLE);
        }
        $remaining = min(4.0, $deadline - microtime(true));
        if ($remaining <= 0) throw new RuntimeException(self::UNAVAILABLE);
        $scope = (string)($receipt['storage_scope'] ?? '');
        $engine = (string)($receipt['storage_engine'] ?? '');
        if (!in_array($scope, ['tenant', 'platform'], true) || !in_array($engine, ['local', 'qcloud', 'aliyun', 'qiniu'], true)) {
            throw new RuntimeException(self::UNAVAILABLE);
        }
        try {
            $config = StorageConfigService::getStoredFileConfig($tenantId, $scope, $engine);
            // Config fallback must not silently move a receipt to another storage scope/engine.
            if (($config['scope'] ?? '') !== $scope || ($config['default'] ?? '') !== $engine || !isset($config['engine'][$engine])) {
                throw new RuntimeException(self::UNAVAILABLE);
            }
            $storage = (array)$config['engine'][$engine];
            $key = self::key((string)($receipt['uri'] ?? ''), $engine, $storage);
            if ($engine === 'local') {
                $root = realpath(public_path().'uploads');
                $path = realpath(public_path().$key);
                if (!$root || !$path || !str_starts_with($path, $root.DIRECTORY_SEPARATOR) || !is_file($path) || filesize($path) > self::MAX_BYTES) {
                    throw new RuntimeException(self::UNAVAILABLE);
                }
                $handle = fopen($path, 'rb');
                if (!$handle) throw new RuntimeException(self::UNAVAILABLE);
                try { $bytes = stream_get_contents($handle, self::MAX_BYTES + 1); }
                finally { fclose($handle); }
            } elseif ($engine === 'qcloud') {
                $bytes = self::cos($storage, $key, $remaining);
            } else {
                // Existing SSRF-pinned transport remains authoritative for non-COS engines.
                // The URI was matched to server storage configuration, not a caller address.
                $url = FileService::getFileUrlByStorage($key, $scope, $engine, (string)($storage['domain'] ?? ''));
                $bytes = self::http($url, $remaining);
            }
            return self::raster($bytes);
        } catch (\Throwable $error) {
            // Provider responses may include credentials, signatures and internal object paths.
            throw new RuntimeException(self::UNAVAILABLE);
        }
    }

    private static function key(string $uri, string $engine, array $storage): string
    {
        if (preg_match('~^https?://~i', $uri)) {
            $parts = parse_url($uri);
            $domain = parse_url((string)($storage['domain'] ?? ''));
            $allowed = strtolower((string)($domain['host'] ?? ''));
            $canonical = $engine === 'qcloud' ? ($storage['bucket'] ?? '').'.cos.'.($storage['region'] ?? '').'.myqcloud.com' : '';
            if (!$parts || !in_array(strtolower((string)($parts['host'] ?? '')), array_filter([$allowed, $canonical]), true)
                || isset($parts['port']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
                throw new RuntimeException(self::UNAVAILABLE);
            }
            $uri = (string)($parts['path'] ?? '');
        }
        if (str_starts_with($uri, '//')) throw new RuntimeException(self::UNAVAILABLE);
        $key = ltrim($uri, '/');
        // Only managed upload keys, no encoded traversal, alternative schemes or path aliases.
        if (!str_starts_with($key, 'uploads/') || strlen($key) > 1024
            || preg_match('~[\x00-\x20\x7f\\\\%?#:]~', $key)
            || preg_match('~(?:^|/)(?:\.|\.\.)(?:/|$)~', $key) || str_contains($key, '//')) {
            throw new RuntimeException(self::UNAVAILABLE);
        }
        return $key;
    }

    private static function cos(array $storage, string $key, float $remaining): string
    {
        $bucket = (string)($storage['bucket'] ?? '');
        $region = (string)($storage['region'] ?? '');
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,61}-[1-9][0-9]{4,19}$/D', $bucket)
            || !preg_match('/^[a-z]{2}(?:-[a-z0-9]+){1,4}$/D', $region)
            || empty($storage['access_key']) || empty($storage['secret_key'])) {
            throw new RuntimeException(self::UNAVAILABLE);
        }
        $config = StorageOperationTimeout::cos(['operation_timeout'=>max(1, (int)ceil($remaining))]) + [
            'region'=>$region, 'scheme'=>'https', 'allow_redirects'=>false,
            'credentials'=>['secretId'=>(string)$storage['access_key'], 'secretKey'=>(string)$storage['secret_key']]
        ];
        $client = new Client($config);
        $result = $client->getObject([
            'Bucket'=>$bucket, 'Key'=>$key, 'Range'=>'bytes=0-'.self::MAX_BYTES,
            '@http'=>[
                'timeout'=>$remaining, 'connect_timeout'=>min(2.0, $remaining),
                'allow_redirects'=>false, 'verify'=>true,
                'on_headers'=>static function ($response): void {
                    $length = $response->getHeaderLine('Content-Length');
                    $range = $response->getHeaderLine('Content-Range');
                    if (($length !== '' && (!ctype_digit($length) || (int)$length > self::MAX_BYTES))
                        || ($range !== '' && (!preg_match('~^bytes [0-9]+-[0-9]+/([0-9]+)$~D', $range, $match) || (int)$match[1] > self::MAX_BYTES))) {
                        throw new RuntimeException(self::UNAVAILABLE);
                    }
                },
                'progress'=>static function ($total, $downloaded): void {
                    if ($total > self::MAX_BYTES || $downloaded > self::MAX_BYTES) throw new RuntimeException(self::UNAVAILABLE);
                }
            ]
        ]);
        if ((int)($result['ContentLength'] ?? 0) > self::MAX_BYTES) throw new RuntimeException(self::UNAVAILABLE);
        $body = $result['Body'] ?? '';
        if (is_string($body)) return $body;
        if (!is_object($body) || !method_exists($body, 'read') || !method_exists($body, 'eof')) throw new RuntimeException(self::UNAVAILABLE);
        $bytes = '';
        try {
            while (!$body->eof()) {
                $part = $body->read(min(65536, self::MAX_BYTES + 1 - strlen($bytes)));
                $bytes .= $part;
                if (strlen($bytes) > self::MAX_BYTES || ($part === '' && !$body->eof())) throw new RuntimeException(self::UNAVAILABLE);
            }
        } finally { if (method_exists($body, 'close')) $body->close(); }
        return $bytes;
    }

    private static function http(string $url, float $remaining): string
    {
        // Reuse the established public-DNS guard; pin that address and reject redirects.
        // One total cURL budget avoids multiplying the avatar budget across redirect hops.
        [$host, $ip] = OpenSafeHttp::resolve($url, false);
        $port = parse_url($url, PHP_URL_SCHEME) === 'https' ? 443 : 80;
        $body = '';
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RESOLVE=>filter_var($host, FILTER_VALIDATE_IP) ? [] : [$host.':'.$port.':'.(str_contains($ip, ':') ? '['.$ip.']' : $ip)],
            CURLOPT_FOLLOWLOCATION=>false, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_CONNECTTIMEOUT_MS=>max(1, (int)floor(min(2.0, $remaining) * 1000)),
            CURLOPT_TIMEOUT_MS=>max(1, (int)floor($remaining * 1000)),
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_RANGE=>'0-'.self::MAX_BYTES,
            CURLOPT_HEADERFUNCTION=>static function ($handle, string $line): int {
                if (preg_match('/^content-length:\s*([0-9]+)/i', $line, $match) && (int)$match[1] > self::MAX_BYTES) return 0;
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION=>static function ($handle, string $part) use (&$body): int {
                if (strlen($body) + strlen($part) > self::MAX_BYTES) return 0;
                $body .= $part;
                return strlen($part);
            }
        ]);
        try {
            $result = curl_exec($curl);
            $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
            if ($result === false || $status < 200 || $status >= 300) throw new RuntimeException(self::UNAVAILABLE);
            return $body;
        } finally { curl_close($curl); }
    }

    private static function raster(string $bytes): array
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) throw new RuntimeException(self::UNAVAILABLE);
        $mime = str_starts_with($bytes, "\x89PNG\r\n\x1a\n") ? 'image/png'
            : (str_starts_with($bytes, "\xff\xd8\xff") ? 'image/jpeg'
                : (substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP' ? 'image/webp' : ''));
        $image = @getimagesizefromstring($bytes);
        if (!$image || $mime === '' || ($image['mime'] ?? '') !== $mime || $image[0] < 1 || $image[1] < 1
            || $image[0] > 8192 || $image[1] > 8192 || $image[0] * $image[1] > 33554432) {
            throw new RuntimeException(self::UNAVAILABLE);
        }
        return ['base64'=>base64_encode($bytes), 'mime'=>$mime];
    }
}
