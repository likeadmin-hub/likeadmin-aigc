<?php

namespace app\common\service\app\aigc_image;

use app\common\enum\FileEnum;
use app\common\model\file\TenantFile;
use app\common\service\storage\Driver as StorageDriver;
use app\common\service\storage\StorageConfigService;
use Exception;

class AigcImageAssetService
{
    public static function persistGeneratedImage(string $url, int $tenantId, int $userId = 0, ?array $storageConfig = null): array
    {
        if (str_starts_with($url, 'data:image/')) {
            return self::persistDataUri($url, $tenantId, $userId, $storageConfig);
        }
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return self::persistRemoteUrl($url, $tenantId, $userId, $storageConfig);
        }
        return ['uri' => $url, 'width' => 0, 'height' => 0, 'stored' => false];
    }

    private static function persistRemoteUrl(string $url, int $tenantId, int $userId, ?array $storageConfig): array
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 25,
                'follow_location' => 1,
                'ignore_errors' => true,
                'header' => "User-Agent: LikeAdminAigcImage/1.0\r\n",
            ],
        ]);
        $content = @file_get_contents($url, false, $context);
        if ($content === false || $content === '') {
            throw new Exception('生成图片下载失败');
        }
        return self::persistBinary($content, $tenantId, $userId, self::extensionFromUrl($url, $content), $storageConfig);
    }

    private static function persistDataUri(string $dataUri, int $tenantId, int $userId, ?array $storageConfig): array
    {
        if (!preg_match('/^data:image\/([a-zA-Z0-9.+-]+);base64,(.+)$/', $dataUri, $matches)) {
            throw new Exception('生成图片格式错误');
        }
        $content = base64_decode($matches[2], true);
        if ($content === false || $content === '') {
            throw new Exception('生成图片解析失败');
        }
        $ext = strtolower($matches[1]);
        return self::persistBinary($content, $tenantId, $userId, $ext === 'jpeg' ? 'jpg' : $ext, $storageConfig);
    }

    private static function persistBinary(string $content, int $tenantId, int $userId, string $ext, ?array $storageConfig): array
    {
        $ext = in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true) ? $ext : 'png';
        $tmp = tempnam(sys_get_temp_dir(), 'aigc_image_');
        if ($tmp === false) {
            throw new Exception('生成图片临时文件创建失败');
        }
        $tmpPath = $tmp . '.' . $ext;
        @rename($tmp, $tmpPath);
        file_put_contents($tmpPath, $content);
        $size = @getimagesize($tmpPath) ?: [];
        try {
            $stored = self::uploadLocalFile($tmpPath, $tenantId, $userId, $storageConfig ?: StorageConfigService::getEffectiveConfig($tenantId));
        } finally {
            @unlink($tmpPath);
        }
        return [
            'uri' => $stored['uri'],
            'width' => (int)($size[0] ?? 0),
            'height' => (int)($size[1] ?? 0),
            'stored' => true,
            'storage_scope' => $stored['storage_scope'],
            'storage_engine' => $stored['storage_engine'],
            'storage_domain' => $stored['storage_domain'],
        ];
    }

    private static function uploadLocalFile(string $filePath, int $tenantId, int $userId, array $config): array
    {
        $saveDir = 'uploads/aigc_image/' . date('Ymd');
        $driver = new StorageDriver($config);
        $driver->setUploadFileByReal($filePath);
        if (!$driver->upload($saveDir)) {
            throw new Exception($driver->getError() ?: '生成图片保存失败');
        }
        $uri = $saveDir . '/' . str_replace('\\', '/', $driver->getFileName());
        $scope = (string)($config['scope'] ?? 'tenant');
        $engine = (string)($config['default'] ?? 'local');
        $domain = StorageConfigService::getStorageDomain($config);
        TenantFile::create([
            'tenant_id' => $tenantId,
            'cid' => 0,
            'type' => FileEnum::IMAGE_TYPE,
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
            'storage_scope' => $scope,
            'storage_engine' => $engine,
            'storage_domain' => $domain,
        ];
    }

    private static function extensionFromUrl(string $url, string $content): string
    {
        $pathExt = strtolower(pathinfo((string)parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        if ($pathExt !== '') {
            return $pathExt;
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
