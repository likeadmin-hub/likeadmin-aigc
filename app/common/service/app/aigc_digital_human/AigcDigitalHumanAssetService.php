<?php

namespace app\common\service\app\aigc_digital_human;

use app\common\enum\FileEnum;
use app\common\model\file\TenantFile;
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
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return self::persistRemoteUrl($url, $tenantId, $userId, 'video');
        }
        return ['uri' => $url, 'width' => 0, 'height' => 0, 'duration' => 0, 'stored' => false];
    }

    public static function persistGeneratedAudio(string $url, int $tenantId, int $userId = 0): array
    {
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return self::persistRemoteUrl($url, $tenantId, $userId, 'audio');
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
        return self::persistBinary($content, $tenantId, $userId, self::extensionFromUrl($url, $content, $kind), $kind);
    }

    private static function persistDataUri(string $dataUri, int $tenantId, int $userId): array
    {
        if (!preg_match('/^data:image\/([a-zA-Z0-9.+-]+);base64,(.+)$/', $dataUri, $matches)) {
            throw new Exception('生成数字人视频格式错误');
        }
        $content = base64_decode($matches[2], true);
        if ($content === false || $content === '') {
            throw new Exception('生成数字人视频解析失败');
        }
        $ext = strtolower($matches[1]);
        return self::persistBinary($content, $tenantId, $userId, $ext === 'jpeg' ? 'jpg' : $ext);
    }

    private static function persistBinary(string $content, int $tenantId, int $userId, string $ext, string $kind = 'image'): array
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
            $uri = self::uploadLocalFile($tmpPath, $tenantId, $userId, $kind);
        } finally {
            @unlink($tmpPath);
        }
        return [
            'uri' => $uri,
            'width' => (int)($size[0] ?? 0),
            'height' => (int)($size[1] ?? 0),
            'duration' => 0,
            'stored' => true,
        ];
    }

    private static function uploadLocalFile(string $filePath, int $tenantId, int $userId, string $kind = 'image'): string
    {
        $config = StorageConfigService::getEffectiveConfig($tenantId);
        $saveDir = 'uploads/aigc_digital_human/' . date('Ymd');
        $driver = new StorageDriver($config);
        $driver->setUploadFileByReal($filePath);
        if (!$driver->upload($saveDir)) {
            throw new Exception($driver->getError() ?: '生成数字人视频保存失败');
        }
        $uri = $saveDir . '/' . str_replace('\\', '/', $driver->getFileName());
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
            'storage_scope' => $config['scope'] ?? 'tenant',
            'storage_engine' => $config['default'] ?? 'local',
            'storage_domain' => self::storageDomainForTenant($tenantId),
            'source' => FileEnum::SOURCE_USER,
            'source_id' => $userId,
            'create_time' => time(),
        ]);
        return $uri;
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

    private static function extensionFromUrl(string $url, string $content, string $kind = 'image'): string
    {
        $pathExt = strtolower(pathinfo((string)parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        if ($pathExt !== '') {
            return $pathExt;
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
}
