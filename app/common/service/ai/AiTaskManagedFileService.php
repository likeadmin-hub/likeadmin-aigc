<?php

namespace app\common\service\ai;

use app\common\enum\FileEnum;
use app\common\model\ai\AiTaskResultAsset;
use app\common\model\file\TenantFile;
use app\common\service\storage\Driver;
use app\common\service\storage\StorageConfigService;
use app\common\service\storage\UploadExtension;
use think\facade\Db;

/** Write-ahead destination and receipt for durable result transfers only. */
class AiTaskManagedFileService
{
    public static function persist(string $path, int $assetId, int $tenantId, int $userId, array $config, array $metadata): array
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $detected = UploadExtension::fromContent($path);
        $mime = (string)(new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if ($detected === '') $detected = match ($mime) {
            'audio/x-hx-aac-adts' => 'aac', 'video/x-m4v' => 'm4v', default => '',
        };
        $equivalent = $detected === $ext || ($detected === 'mp4' && $ext === 'm4v') || ($detected === 'ogg' && $ext === 'opus');
        if (!$equivalent || !is_file($path) || is_link($path)) {
            throw new \RuntimeException('结果文件类型与实际内容不匹配');
        }
        // Delivery metadata comes from the verified file, never a supplier header.
        $metadata['mime_type'] = match ($mime) {
            'audio/x-wav', 'audio/wave', 'audio/vnd.wave' => 'audio/wav',
            'audio/x-hx-aac-adts' => 'audio/aac', 'audio/x-m4a' => 'audio/mp4',
            'audio/x-flac' => 'audio/flac', 'video/x-m4v' => 'video/mp4',
            default => $mime,
        };
        $bytes = (int)filesize($path);
        $checksum = hash_file('sha256', $path);
        $checkpoint = Db::transaction(function () use ($assetId, $tenantId, $userId, $config, $metadata, $ext, $bytes, $checksum) {
            $asset = self::owned($assetId, $tenantId, $userId);
            $allowed = match ($asset['asset_type']) {
                'image' => ['png', 'jpg', 'jpeg', 'webp', 'gif'],
                'video' => ['mp4', 'm4v', 'webm', 'mov'],
                'audio' => ['mp3', 'wav', 'ogg', 'flac', 'aac', 'm4a', 'opus'],
                default => [],
            };
            if ($bytes < 1 || !in_array($ext, $allowed, true)) throw new \RuntimeException('结果文件类型无效');
            $meta = (array)$asset['storage_meta'];
            $planned = self::identity($asset, $config) + [
                'uri' => 'uploads/ai_result/' . $tenantId . '/' . $userId . '/asset-' . $assetId . '-' . $checksum . '.' . $ext,
                'checksum' => $checksum, 'file_size' => $bytes,
                'metadata' => array_intersect_key($metadata, array_flip(['width', 'height', 'duration', 'mime_type'])),
            ];
            $planned = json_decode(json_encode($planned, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
            if (isset($meta['transfer_checkpoint'])) {
                foreach (array_diff_key($planned, ['metadata' => true]) as $key => $value) {
                    if (($meta['transfer_checkpoint'][$key] ?? null) !== $value) throw new \RuntimeException('转存来源或存储配置已变化，请核对');
                }
                return $meta['transfer_checkpoint'];
            }
            $meta['transfer_checkpoint'] = $planned;
            $asset->save(['storage_meta' => $meta, 'update_time' => time()]);
            return $planned;
        });
        $asset = AiTaskResultAsset::find($assetId);
        $recovered = self::recover($asset, $config);
        if ($recovered !== null) return $recovered;

        // No database transaction is held during storage I/O. Retries use this exact object key.
        $driver = new Driver($config);
        $driver->setUploadFileByReal($path, basename($checkpoint['uri']));
        if (!$driver->upload(dirname($checkpoint['uri']))) throw new \RuntimeException($driver->getError() ?: '结果文件保存失败');
        return self::receipt($assetId, $tenantId, $userId, $checkpoint);
    }

    public static function recover(AiTaskResultAsset $asset, array $config): ?array
    {
        $checkpoint = ((array)$asset['storage_meta'])['transfer_checkpoint'] ?? null;
        if (!is_array($checkpoint)) return null;
        foreach (self::identity($asset, $config) as $key => $value) {
            if (($checkpoint[$key] ?? null) !== $value) throw new \RuntimeException('转存来源或存储配置已变化，请核对');
        }
        $expected = 'uploads/ai_result/' . $asset['tenant_id'] . '/' . $asset['user_id'] . '/asset-' . $asset['id'] . '-';
        if (!preg_match('/^[a-f0-9]{64}$/D', (string)($checkpoint['checksum'] ?? ''))
            || !preg_match('~^' . preg_quote($expected . $checkpoint['checksum'], '~') . '\\.(png|jpg|jpeg|webp|gif|mp4|m4v|webm|mov|mp3|wav|ogg|flac|aac|m4a|opus)$~D', (string)($checkpoint['uri'] ?? ''))) {
            throw new \RuntimeException('转存检查点无效');
        }
        if ($checkpoint['storage_engine'] === 'local') {
            $path = public_path() . $checkpoint['uri'];
            clearstatcache(true, $path);
            if (!is_file($path) || is_link($path) || filesize($path) !== $checkpoint['file_size']
                || hash_file('sha256', $path) !== $checkpoint['checksum']) return null;
            return self::receipt((int)$asset['id'], (int)$asset['tenant_id'], (int)$asset['user_id'], $checkpoint);
        }
        // Cloud recovery requires a durable upload receipt; a planned destination alone is insufficient.
        if (!isset($checkpoint['receipt'])) return null;
        return self::receipt((int)$asset['id'], (int)$asset['tenant_id'], (int)$asset['user_id'], $checkpoint);
    }

    private static function receipt(int $assetId, int $tenantId, int $userId, array $checkpoint): array
    {
        return Db::transaction(function () use ($assetId, $tenantId, $userId, $checkpoint) {
            $asset = self::owned($assetId, $tenantId, $userId);
            $meta = (array)$asset['storage_meta'];
            if (($meta['transfer_checkpoint'] ?? null) !== $checkpoint) throw new \RuntimeException('转存检查点已变化');
            $type = match ($asset['asset_type']) {'image' => FileEnum::IMAGE_TYPE, 'video' => FileEnum::VIDEO_TYPE, default => FileEnum::FILE_TYPE};
            $file = TenantFile::where(['tenant_id' => $tenantId, 'uri' => $checkpoint['uri']])->findOrEmpty();
            if ($file->isEmpty()) {
                if (isset($checkpoint['receipt'])) throw new \RuntimeException('转存文件回执缺失，请核对');
                $file = TenantFile::create([
                    'tenant_id' => $tenantId, 'cid' => 0, 'type' => $type, 'name' => basename($checkpoint['uri']),
                    'uri' => $checkpoint['uri'], 'storage_scope' => $checkpoint['storage_scope'],
                    'storage_engine' => $checkpoint['storage_engine'], 'storage_domain' => $checkpoint['storage_domain'],
                    'source' => FileEnum::SOURCE_USER, 'source_id' => $userId, 'create_time' => time(),
                ]);
            }
            foreach (['source_id' => $userId, 'source' => FileEnum::SOURCE_USER, 'type' => $type] as $key => $value) {
                if ((int)$file[$key] !== $value) throw new \RuntimeException('转存文件归属不匹配');
            }
            foreach (['storage_scope', 'storage_engine', 'storage_domain'] as $key) {
                if ((string)$file[$key] !== $checkpoint[$key]) throw new \RuntimeException('转存文件存储信息不匹配');
            }
            $receipt = $checkpoint['metadata'] + array_intersect_key($checkpoint, array_flip(['uri', 'file_size', 'checksum', 'storage_scope', 'storage_engine', 'storage_domain'])) + ['stored' => true, 'file_id' => (int)$file['id']];
            if (isset($checkpoint['receipt']) && $checkpoint['receipt'] !== $receipt) throw new \RuntimeException('转存文件回执不匹配');
            if (!isset($checkpoint['receipt'])) {
                $meta['transfer_checkpoint']['receipt'] = $receipt;
                $asset->save(['storage_meta' => $meta, 'update_time' => time()]);
            }
            return $receipt;
        });
    }

    private static function owned(int $assetId, int $tenantId, int $userId): AiTaskResultAsset
    {
        $asset = AiTaskResultAsset::lock(true)->findOrEmpty($assetId);
        if ($asset->isEmpty() || (int)$asset['tenant_id'] !== $tenantId || (int)$asset['user_id'] !== $userId) throw new \RuntimeException('转存资产归属不匹配');
        return $asset;
    }

    private static function identity(AiTaskResultAsset $asset, array $config): array
    {
        return ['version' => 1, 'source_sha256' => hash('sha256', (string)$asset['external_url']),
            'storage_scope' => (string)($config['scope'] ?? 'tenant'), 'storage_engine' => (string)$config['default'],
            'storage_domain' => (string)StorageConfigService::getStorageDomain($config)];
    }
}
