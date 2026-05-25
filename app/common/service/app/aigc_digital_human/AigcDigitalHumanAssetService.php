<?php

namespace app\common\service\app\aigc_digital_human;

use app\common\enum\FileEnum;
use app\common\model\file\TenantFile;
use app\common\service\FileService;
use app\common\service\storage\Driver as StorageDriver;
use app\common\service\storage\StorageConfigService;
use Exception;

class AigcDigitalHumanAssetService
{
    public static function persistGeneratedImage(string $url, int $tenantId, int $userId = 0): array
    {
        if (str_starts_with($url, 'data:image/')) {
            return self::persistDataUri($url, $tenantId, $userId);
        }
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return self::persistRemoteUrl($url, $tenantId, $userId);
        }
        return ['uri' => $url, 'width' => 0, 'height' => 0, 'stored' => false];
    }

    public static function persistGeneratedVideo(string $url, int $tenantId, int $userId = 0): array
    {
        if (str_starts_with($url, 'data:video/')) {
            return self::persistDataUri($url, $tenantId, $userId, 'video');
        }
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return self::persistRemoteUrl($url, $tenantId, $userId, 'video');
        }
        if (!self::isAllowedLocalAssetUri($url, 'video')) {
            throw new Exception('供应商返回的视频地址无效');
        }
        return ['uri' => $url, 'width' => 0, 'height' => 0, 'duration' => 0, 'stored' => false];
    }

    public static function persistGeneratedAudio(string $url, int $tenantId, int $userId = 0): array
    {
        if (str_starts_with($url, 'data:audio/')) {
            return self::persistDataUri($url, $tenantId, $userId, 'audio');
        }
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return self::persistRemoteUrl($url, $tenantId, $userId, 'audio');
        }
        if (!self::isAllowedLocalAssetUri($url, 'audio')) {
            throw new Exception('供应商返回的音频地址无效');
        }
        return ['uri' => $url, 'duration' => 0, 'stored' => false];
    }

    private static function persistRemoteUrl(string $url, int $tenantId, int $userId, string $kind = 'image'): array
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 25,
                'follow_location' => 1,
                'ignore_errors' => true,
                'header' => "User-Agent: LikeAdminAigcDigitalHuman/1.0\r\n",
            ],
        ]);
        $content = @file_get_contents($url, false, $context);
        if ($content === false || $content === '') {
            throw new Exception('生成数字人视频下载失败');
        }
        $headers = isset($http_response_header) && is_array($http_response_header) ? $http_response_header : [];
        if (!self::isSuccessfulResponse($headers)) {
            throw new Exception('生成数字人视频下载失败：供应商文件不可访问');
        }
        $mime = self::contentTypeFromHeaders($headers);
        if (!self::isAllowedMime($mime, $kind)) {
            throw new Exception('生成数字人视频下载失败：供应商文件类型不正确');
        }
        return self::persistBinary($content, $tenantId, $userId, self::extensionFromUrl($url, $content, $kind, $mime), $kind, $mime);
    }

    private static function persistDataUri(string $dataUri, int $tenantId, int $userId, string $kind = 'image'): array
    {
        if (!preg_match('/^data:(image|video|audio)\/([a-zA-Z0-9.+-]+);base64,(.+)$/', $dataUri, $matches)) {
            throw new Exception('生成数字人视频格式错误');
        }
        $content = base64_decode($matches[3], true);
        if ($content === false || $content === '') {
            throw new Exception('生成数字人视频解析失败');
        }
        $ext = strtolower($matches[2]);
        return self::persistBinary($content, $tenantId, $userId, $ext === 'jpeg' ? 'jpg' : $ext, $kind, $matches[1] . '/' . $matches[2]);
    }

    private static function persistBinary(string $content, int $tenantId, int $userId, string $ext, string $kind = 'image', string $mime = ''): array
    {
        $allowed = match ($kind) {
            'video' => ['mp4', 'mov', 'webm', 'm4v'],
            'audio' => ['mp3', 'wav', 'ogg', 'flac', 'aac', 'm4a', 'opus'],
            default => ['jpg', 'jpeg', 'png', 'webp', 'gif'],
        };
        $ext = in_array($ext, $allowed, true) ? $ext : match ($kind) {
            'video' => 'mp4',
            'audio' => 'mp3',
            default => 'png',
        };
        $tmp = tempnam(sys_get_temp_dir(), 'aigc_digital_human_');
        if ($tmp === false) {
            throw new Exception('生成数字人视频临时文件创建失败');
        }
        $tmpPath = $tmp . '.' . $ext;
        @rename($tmp, $tmpPath);
        file_put_contents($tmpPath, $content);
        $size = $kind === 'image' ? (@getimagesize($tmpPath) ?: []) : [];
        try {
            $stored = self::uploadLocalFile($tmpPath, $tenantId, $userId, $kind);
        } finally {
            @unlink($tmpPath);
        }
        return [
            'uri' => $stored['uri'],
            'url' => $stored['url'],
            'storage_scope' => $stored['storage_scope'],
            'storage_engine' => $stored['storage_engine'],
            'storage_domain' => $stored['storage_domain'],
            'mime_type' => $mime ?: self::mimeByExtension($ext, $kind),
            'file_size' => strlen($content),
            'width' => (int)($size[0] ?? 0),
            'height' => (int)($size[1] ?? 0),
            'duration' => 0,
            'stored' => true,
        ];
    }

    public static function uploadLocalFile(string $filePath, int $tenantId, int $userId, string $kind = 'image'): array
    {
        $config = StorageConfigService::getEffectiveConfig($tenantId);
        $saveDir = 'uploads/aigc_digital_human/' . date('Ymd');
        $driver = new StorageDriver($config);
        $driver->setUploadFileByReal($filePath);
        if (!$driver->upload($saveDir)) {
            throw new Exception($driver->getError() ?: '生成数字人视频保存失败');
        }
        $uri = $saveDir . '/' . str_replace('\\', '/', $driver->getFileName());
        $scope = $config['scope'] ?? 'tenant';
        $engine = $config['default'] ?? 'local';
        $domain = self::storageDomainForTenant($tenantId);
        TenantFile::create([
            'tenant_id' => $tenantId,
            'cid' => 0,
            'type' => match ($kind) {
                'video' => FileEnum::VIDEO_TYPE,
                'audio' => FileEnum::FILE_TYPE,
                default => FileEnum::IMAGE_TYPE,
            },
            'name' => basename($uri),
            'uri' => $uri,
            'storage_scope' => $scope,
            'storage_engine' => $engine,
            'storage_domain' => $domain,
            'source' => FileEnum::SOURCE_USER,
            'source_id' => $userId,
            'create_time' => time(),
        ]);
        return [
            'uri' => $uri,
            'url' => FileService::getFileUrlByStorage($uri, $scope, $engine, $domain),
            'storage_scope' => $scope,
            'storage_engine' => $engine,
            'storage_domain' => $domain,
        ];
    }

    private static function storageDomainForTenant(int $tenantId): string
    {
        if (StorageConfigService::getEffectiveDefault($tenantId) !== 'local') {
            return StorageConfigService::getEffectiveDomain($tenantId);
        }
        $domain = trim((string)request()->domain());
        if ($domain !== '' && !in_array($domain, ['http://', 'https://'], true)) {
            return $domain;
        }
        $host = trim((string)config('project.http_host'));
        if ($host === '') {
            return '';
        }
        return preg_match('/^https?:\/\//i', $host) ? $host : 'http://' . $host;
    }

    private static function extensionFromUrl(string $url, string $content, string $kind = 'image', string $mime = ''): string
    {
        $pathExt = strtolower(pathinfo((string)parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        if ($pathExt !== '') {
            return $pathExt;
        }
        $mimeExt = self::extensionByMime($mime);
        if ($mimeExt !== '') {
            return $mimeExt;
        }
        if ($kind === 'video') {
            return 'mp4';
        }
        if ($kind === 'audio') {
            return 'mp3';
        }
        $info = @getimagesizefromstring($content);
        $mime = strtolower((string)($info['mime'] ?? ''));
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'png',
        };
    }

    private static function isSuccessfulResponse(array $headers): bool
    {
        $status = '';
        foreach ($headers as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/i', $header, $matches)) {
                $status = $matches[1];
            }
        }
        return $status === '' || ((int)$status >= 200 && (int)$status < 300);
    }

    private static function contentTypeFromHeaders(array $headers): string
    {
        foreach ($headers as $header) {
            if (stripos($header, 'Content-Type:') === 0) {
                return strtolower(trim(explode(';', trim(substr($header, 13)))[0]));
            }
        }
        return '';
    }

    private static function isAllowedMime(string $mime, string $kind): bool
    {
        if ($mime === '' || $mime === 'application/octet-stream') {
            return true;
        }
        return match ($kind) {
            'video' => str_starts_with($mime, 'video/'),
            'audio' => str_starts_with($mime, 'audio/'),
            default => str_starts_with($mime, 'image/'),
        };
    }

    private static function extensionByMime(string $mime): string
    {
        return match (strtolower($mime)) {
            'video/mp4' => 'mp4',
            'video/quicktime' => 'mov',
            'video/webm' => 'webm',
            'audio/mpeg' => 'mp3',
            'audio/mp4', 'audio/x-m4a' => 'm4a',
            'audio/aac' => 'aac',
            'audio/wav', 'audio/x-wav' => 'wav',
            'audio/ogg' => 'ogg',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => '',
        };
    }

    private static function mimeByExtension(string $extension, string $kind): string
    {
        $mime = match (strtolower($extension)) {
            'mp4', 'm4v' => 'video/mp4',
            'mov' => 'video/quicktime',
            'webm' => 'video/webm',
            'mp3' => 'audio/mpeg',
            'm4a' => 'audio/mp4',
            'aac' => 'audio/aac',
            'wav' => 'audio/wav',
            'ogg' => 'audio/ogg',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => '',
        };
        if ($mime !== '') {
            return $mime;
        }
        return match ($kind) {
            'video' => 'video/mp4',
            'audio' => 'audio/mpeg',
            default => 'image/png',
        };
    }

    private static function isAllowedLocalAssetUri(string $uri, string $kind): bool
    {
        $path = ltrim((string)(parse_url($uri, PHP_URL_PATH) ?: $uri), '/');
        if ($path === '' || (!str_starts_with($path, 'uploads/') && !str_starts_with($path, 'resource/'))) {
            return false;
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $allowed = match ($kind) {
            'video' => ['mp4', 'mov', 'webm', 'm4v'],
            'audio' => ['mp3', 'wav', 'ogg', 'flac', 'aac', 'm4a', 'opus'],
            default => ['jpg', 'jpeg', 'png', 'webp', 'gif'],
        };
        return in_array($ext, $allowed, true);
    }
}
