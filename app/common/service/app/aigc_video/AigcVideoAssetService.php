<?php

namespace app\common\service\app\aigc_video;

use app\common\enum\FileEnum;
use app\common\model\file\TenantFile;
use app\common\service\storage\Driver as StorageDriver;
use app\common\service\storage\StorageConfigService;
use Exception;

class AigcVideoAssetService
{
    public static function persistGeneratedVideo(string $url, int $tenantId, int $userId = 0): array
    {
        if (str_starts_with($url, 'data:video/')) {
            return self::persistDataUri($url, $tenantId, $userId);
        }
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return self::persistRemoteUrl($url, $tenantId, $userId);
        }
        if (!self::isAllowedLocalVideoUri($url)) {
            throw new Exception('供应商返回的视频地址无效');
        }
        return ['uri' => $url, 'width' => 0, 'height' => 0, 'stored' => false];
    }

    private static function persistRemoteUrl(string $url, int $tenantId, int $userId): array
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 25,
                'follow_location' => 1,
                'ignore_errors' => true,
                'header' => "User-Agent: LikeAdminAigcVideo/1.0\r\n",
            ],
        ]);
        $content = @file_get_contents($url, false, $context);
        if ($content === false || $content === '') {
            throw new Exception('生成视频下载失败');
        }
        $headers = $http_response_header ?? [];
        $httpCode = self::lastHttpStatusCode($headers);
        if ($httpCode >= 400) {
            throw new Exception('生成视频下载失败，远程地址返回HTTP ' . $httpCode);
        }
        $contentType = self::lastContentType($headers);
        if (self::isTextResponse($contentType, $content)) {
            throw new Exception('生成视频下载失败，远程地址返回的不是视频文件');
        }
        return self::persistBinary($content, $tenantId, $userId, self::extensionFromUrl($url, $content, $contentType));
    }

    private static function persistDataUri(string $dataUri, int $tenantId, int $userId): array
    {
        if (!preg_match('/^data:video\/([a-zA-Z0-9.+-]+);base64,(.+)$/', $dataUri, $matches)) {
            throw new Exception('生成视频格式错误');
        }
        $content = base64_decode($matches[2], true);
        if ($content === false || $content === '') {
            throw new Exception('生成视频解析失败');
        }
        $ext = strtolower($matches[1]);
        return self::persistBinary($content, $tenantId, $userId, $ext === 'jpeg' ? 'jpg' : $ext);
    }

    private static function persistBinary(string $content, int $tenantId, int $userId, string $ext): array
    {
        $detectedExt = self::detectVideoExtension($content);
        if ($detectedExt === '') {
            throw new Exception('生成视频文件损坏或格式不支持');
        }
        $ext = in_array($ext, ['mp4', 'webm', 'mov', 'm4v'], true) ? $ext : $detectedExt;
        $tmp = tempnam(sys_get_temp_dir(), 'aigc_video_');
        if ($tmp === false) {
            throw new Exception('生成视频临时文件创建失败');
        }
        $tmpPath = $tmp . '.' . $ext;
        @rename($tmp, $tmpPath);
        file_put_contents($tmpPath, $content);
        try {
            $uri = self::uploadLocalFile($tmpPath, $tenantId, $userId);
            $config = StorageConfigService::getEffectiveConfig($tenantId);
        } finally {
            @unlink($tmpPath);
        }
        return [
            'uri' => $uri,
            'width' => 0,
            'height' => 0,
            'storage_scope' => (string)($config['scope'] ?? 'tenant'),
            'storage_engine' => (string)($config['default'] ?? 'local'),
            'storage_domain' => (string)StorageConfigService::getEffectiveDomain($tenantId),
            'stored' => true,
        ];
    }

    private static function uploadLocalFile(string $filePath, int $tenantId, int $userId): string
    {
        $config = StorageConfigService::getEffectiveConfig($tenantId);
        $saveDir = 'uploads/aigc_video/' . date('Ymd');
        $driver = new StorageDriver($config);
        $driver->setUploadFileByReal($filePath);
        if (!$driver->upload($saveDir)) {
            throw new Exception($driver->getError() ?: '生成视频保存失败');
        }
        $uri = $saveDir . '/' . str_replace('\\', '/', $driver->getFileName());
        TenantFile::create([
            'tenant_id' => $tenantId,
            'cid' => 0,
            'type' => FileEnum::VIDEO_TYPE,
            'name' => basename($uri),
            'uri' => $uri,
            'storage_scope' => $config['scope'] ?? 'tenant',
            'storage_engine' => $config['default'] ?? 'local',
            'storage_domain' => StorageConfigService::getEffectiveDomain($tenantId),
            'source' => FileEnum::SOURCE_USER,
            'source_id' => $userId,
            'create_time' => time(),
        ]);
        return $uri;
    }

    private static function extensionFromUrl(string $url, string $content, string $contentType = ''): string
    {
        $pathExt = strtolower(pathinfo((string)parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        if (in_array($pathExt, ['mp4', 'webm', 'mov', 'm4v'], true)) {
            return $pathExt;
        }
        $mimeExt = match (strtolower(trim(explode(';', $contentType)[0] ?? ''))) {
            'video/webm' => 'webm',
            'video/quicktime' => 'mov',
            'video/x-m4v' => 'm4v',
            'video/mp4', 'application/mp4' => 'mp4',
            default => '',
        };
        return $mimeExt ?: self::detectVideoExtension($content) ?: 'mp4';
    }

    private static function lastHttpStatusCode(array $headers): int
    {
        $status = 0;
        foreach ($headers as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/i', (string)$header, $matches)) {
                $status = (int)$matches[1];
            }
        }
        return $status;
    }

    private static function lastContentType(array $headers): string
    {
        $contentType = '';
        foreach ($headers as $header) {
            if (stripos((string)$header, 'Content-Type:') === 0) {
                $contentType = trim(substr((string)$header, strlen('Content-Type:')));
            }
        }
        return $contentType;
    }

    private static function isTextResponse(string $contentType, string $content): bool
    {
        $mime = strtolower(trim(explode(';', $contentType)[0] ?? ''));
        if ($mime !== '' && (str_starts_with($mime, 'text/') || in_array($mime, ['application/json', 'application/xml'], true))) {
            return true;
        }
        $prefix = ltrim(substr($content, 0, 64));
        return str_starts_with($prefix, '<') || str_starts_with($prefix, '{') || str_starts_with($prefix, '[');
    }

    private static function detectVideoExtension(string $content): string
    {
        if (strlen($content) < 16) {
            return '';
        }
        if (str_starts_with($content, "\x1A\x45\xDF\xA3")) {
            return 'webm';
        }
        if (substr($content, 4, 4) === 'ftyp') {
            $brand = strtolower(substr($content, 8, 4));
            return str_starts_with($brand, 'qt') ? 'mov' : 'mp4';
        }
        return '';
    }

    private static function isAllowedLocalVideoUri(string $uri): bool
    {
        $path = ltrim((string)(parse_url($uri, PHP_URL_PATH) ?: $uri), '/');
        if ($path === '' || (!str_starts_with($path, 'uploads/') && !str_starts_with($path, 'resource/'))) {
            return false;
        }
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['mp4', 'webm', 'mov', 'm4v'], true);
    }
}
