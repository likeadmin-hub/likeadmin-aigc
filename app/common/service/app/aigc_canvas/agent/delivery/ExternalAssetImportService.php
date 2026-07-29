<?php

namespace app\common\service\app\aigc_canvas\agent\delivery;

use app\common\enum\FileEnum;
use app\common\model\file\TenantFile;
use app\common\service\app\aigc_canvas\AigcCanvasAgentService;
use app\common\service\storage\Driver as StorageDriver;
use app\common\service\storage\StorageConfigService;
use Exception;

final class ExternalAssetImportService
{
    private const MAX_BYTES = 25 * 1024 * 1024;
    private const MAX_REDIRECTS = 3;
    private const CONNECT_TIMEOUT = 5;
    private const TOTAL_TIMEOUT = 20;

    public static function importImage(int $tenantId, int $userId, int $itemId, string $url, array $params = []): array
    {
        $item = DeliveryItemService::find($tenantId, $userId, $itemId);
        if ($item === []) throw new Exception('Delivery item not found');
        $download = self::download($url);
        try {
            $image = @getimagesize($download['path']);
            if (!is_array($image) || (int)($image[0] ?? 0) < 1 || (int)($image[1] ?? 0) < 1) {
                throw new Exception('External asset is not a valid image');
            }
            if ((int)$image[0] * (int)$image[1] > 100000000) throw new Exception('External image dimensions are too large');
            $mime = strtolower((string)($image['mime'] ?? ''));
            $extension = self::extension($mime);
            if ($extension === '') throw new Exception('External image format is not supported');
            $stored = self::store($download['path'], $extension, $tenantId, $userId);
            $asset = AigcCanvasAgentService::registerAsset($tenantId, $userId, [
                'project_id' => (int)$item['project_id'], 'asset_type' => 'reference_image',
                'title' => (string)($params['title'] ?? 'External reference image'), 'uri' => $stored['uri'],
                'mime_type' => $mime, 'file_size' => (int)$download['bytes'], 'width' => (int)$image[0], 'height' => (int)$image[1],
                'storage_scope' => $stored['storage_scope'], 'storage_engine' => $stored['storage_engine'], 'storage_domain' => $stored['storage_domain'],
                'source_type' => 'external_asset_import', 'meta' => ['source_url' => $download['url']],
            ]);
            $references = (array)$item['reference_assets'];
            $references[] = [
                'id' => (int)$asset['id'], 'type' => 'image', 'asset_type' => 'reference_image', 'role' => 'reference_image',
                'url' => (string)$asset['url'], 'uri' => (string)$asset['uri'], 'name' => (string)$asset['title'],
            ];
            $updated = DeliveryItemContextBinder::bind($tenantId, $userId, $itemId, [], [], [
                'reference_assets' => self::uniqueReferences($references),
            ]);
            return ['asset' => $asset, 'delivery_item' => $updated, 'source_url' => $download['url']];
        } finally {
            @unlink($download['path']);
        }
    }

    /** Public for deterministic contract testing before any HTTP request is opened. */
    public static function assertPublicUrl(string $url): void
    {
        $parts = parse_url(trim($url));
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new Exception('External asset URL must be a public HTTP(S) URL');
        }
        if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.localhost')) {
            throw new Exception('External asset URL cannot target a local address');
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if (!filter_var($host, FILTER_VALIDATE_IP) && function_exists('dns_get_record')) {
            foreach ((array)dns_get_record($host, DNS_AAAA) as $record) {
                $ipv6 = (string)($record['ipv6'] ?? '');
                if ($ipv6 !== '') $ips[] = $ipv6;
            }
        }
        if ($ips === []) throw new Exception('External asset host cannot be resolved');
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new Exception('External asset URL cannot target a private network');
            }
        }
    }

    private static function download(string $url): array
    {
        $current = trim($url);
        for ($redirect = 0; $redirect <= self::MAX_REDIRECTS; $redirect++) {
            self::assertPublicUrl($current);
            $temp = tempnam(sys_get_temp_dir(), 'canvas_external_');
            if ($temp === false) throw new Exception('Cannot create temporary file for external asset');
            $handle = fopen($temp, 'wb');
            if ($handle === false) throw new Exception('Cannot open temporary file for external asset');
            $bytes = 0;
            $redirectLocation = '';
            $curl = curl_init($current);
            curl_setopt_array($curl, [
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_RETURNTRANSFER => false, CURLOPT_HEADER => false,
                CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT, CURLOPT_TIMEOUT => self::TOTAL_TIMEOUT,
                CURLOPT_USERAGENT => 'LikeAdmin-AIGC-Canvas/1.0', CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_HEADERFUNCTION => static function ($ch, string $header) use (&$redirectLocation): int {
                    if (str_starts_with(strtolower($header), 'location:')) $redirectLocation = trim(substr($header, 9));
                    return strlen($header);
                },
                CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use ($handle, &$bytes): int {
                    $bytes += strlen($chunk);
                    if ($bytes > self::MAX_BYTES) return 0;
                    return fwrite($handle, $chunk) ?: 0;
                },
            ]);
            $ok = curl_exec($curl);
            $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $contentType = strtolower(trim((string)curl_getinfo($curl, CURLINFO_CONTENT_TYPE)));
            $location = $redirectLocation ?: trim((string)curl_getinfo($curl, CURLINFO_REDIRECT_URL));
            $error = (string)curl_error($curl);
            curl_close($curl);
            fclose($handle);
            if ($status >= 300 && $status < 400 && $location !== '') {
                @unlink($temp);
                $current = self::absoluteRedirect($current, $location);
                continue;
            }
            if ($ok === false) {
                @unlink($temp);
                throw new Exception($bytes > self::MAX_BYTES ? 'External asset exceeds the 25 MB limit' : ('External asset download failed: ' . $error));
            }
            if ($status < 200 || $status >= 300) {
                @unlink($temp);
                throw new Exception('External asset server returned HTTP ' . $status);
            }
            if ($bytes <= 0 || $bytes > self::MAX_BYTES) {
                @unlink($temp);
                throw new Exception('External asset size is invalid');
            }
            if ($contentType !== '' && !str_starts_with($contentType, 'image/')) {
                @unlink($temp);
                throw new Exception('External asset content type is not an image');
            }
            return ['path' => $temp, 'bytes' => $bytes, 'url' => $current];
        }
        throw new Exception('External asset has too many redirects');
    }

    private static function store(string $path, string $extension, int $tenantId, int $userId): array
    {
        $renamed = $path . '.' . $extension;
        @rename($path, $renamed);
        $config = StorageConfigService::getEffectiveConfig($tenantId);
        $driver = new StorageDriver($config);
        $driver->setUploadFileByReal($renamed);
        $saveDir = 'uploads/aigc_canvas/external/' . date('Ymd');
        try {
            if (!$driver->upload($saveDir)) throw new Exception($driver->getError() ?: 'External asset storage failed');
        } finally {
            @unlink($renamed);
        }
        $uri = $saveDir . '/' . str_replace('\\', '/', $driver->getFileName());
        $scope = (string)($config['scope'] ?? 'tenant');
        $engine = (string)($config['default'] ?? 'local');
        $domain = (string)StorageConfigService::getEffectiveDomain($tenantId);
        TenantFile::create([
            'tenant_id' => $tenantId, 'cid' => 0, 'type' => FileEnum::IMAGE_TYPE, 'name' => basename($uri), 'uri' => $uri,
            'storage_scope' => $scope, 'storage_engine' => $engine, 'storage_domain' => $domain,
            'source' => FileEnum::SOURCE_USER, 'source_id' => $userId, 'create_time' => time(),
        ]);
        return ['uri' => $uri, 'storage_scope' => $scope, 'storage_engine' => $engine, 'storage_domain' => $domain];
    }

    private static function absoluteRedirect(string $current, string $location): string
    {
        if (preg_match('#^https?://#i', $location) === 1) return $location;
        $parts = parse_url($current);
        $prefix = (string)($parts['scheme'] ?? 'https') . '://' . (string)($parts['host'] ?? '');
        if (str_starts_with($location, '/')) return $prefix . $location;
        $path = rtrim(dirname((string)($parts['path'] ?? '/')), '/');
        return $prefix . ($path === '' ? '/' : $path . '/') . $location;
    }

    private static function extension(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', default => '',
        };
    }

    private static function uniqueReferences(array $references): array
    {
        $unique = [];
        foreach ($references as $reference) {
            if (!is_array($reference)) continue;
            $url = trim((string)($reference['url'] ?? $reference['uri'] ?? ''));
            if ($url !== '') $unique[$url] = $reference;
        }
        return array_values($unique);
    }
}
