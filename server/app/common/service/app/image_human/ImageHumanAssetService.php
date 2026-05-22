<?php

namespace app\common\service\app\image_human;

use app\common\enum\FileEnum;
use app\common\model\file\TenantFile;
use app\common\service\storage\Driver as StorageDriver;
use app\common\service\storage\StorageConfigService;
use Exception;

class ImageHumanAssetService
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
                'timeout' => 30,
                'follow_location' => 1,
                'ignore_errors' => true,
                'header' => "User-Agent: LikeAdminImageHuman/1.0\r\n",
            ],
        ]);
        $content = @file_get_contents($url, false, $context);
        if ($content === false || $content === '') {
            throw new Exception('生成视频下载失败');
        }
        return self::persistBinary($content, $tenantId, $userId, self::extensionFromUrl($url, $content));
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
        $ext = in_array($ext, ['mp4', 'webm', 'mov', 'm4v'], true) ? $ext : 'mp4';
        $tmp = tempnam(sys_get_temp_dir(), 'image_human_');
        if ($tmp === false) {
            throw new Exception('生成视频临时文件创建失败');
        }
        $tmpPath = $tmp . '.' . $ext;
        @rename($tmp, $tmpPath);
        file_put_contents($tmpPath, $content);
        try {
            $uri = self::uploadLocalFile($tmpPath, $tenantId, $userId);
        } finally {
            @unlink($tmpPath);
        }
        return [
            'uri' => $uri,
            'width' => 0,
            'height' => 0,
            'stored' => true,
        ];
    }

    private static function uploadLocalFile(string $filePath, int $tenantId, int $userId): string
    {
        $config = StorageConfigService::getEffectiveConfig($tenantId);
        $saveDir = 'uploads/image_human/' . date('Ymd');
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

    private static function extensionFromUrl(string $url, string $content): string
    {
        $pathExt = strtolower(pathinfo((string)parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        if ($pathExt !== '') {
            return $pathExt;
        }
        return str_starts_with($content, "\x1A\x45\xDF\xA3") ? 'webm' : 'mp4';
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
