<?php

namespace app\common\service\ai;

use app\common\model\ai\AiConsumptionLog;
use app\common\model\ai\AiTaskResultAsset;
use app\common\service\app\aigc_image\AigcImageAssetService;
use app\common\service\app\aigc_music\AigcMusicAssetService;
use app\common\service\app\aigc_video\AigcVideoAssetService;
use Exception;

class AiTaskResultAssetService
{
    public static function recordConsumptionAssets(int $consumptionId, bool $forceTransfer = false): array
    {
        $consumption = AiConsumptionLog::findOrEmpty($consumptionId);
        if ($consumption->isEmpty()) {
            return [];
        }
        $summary = self::arrayValue($consumption['response_summary'] ?? []);
        $assets = [];
        foreach ([['images', 'image', 'image_uri'], ['videos', 'video', 'video_uri'], ['items', 'audio', 'audio_uri']] as [$key, $type, $uriKey]) {
            foreach ((array)($summary[$key] ?? []) as $item) {
                $item = is_array($item) ? $item : [];
                $uri = trim((string)($item[$uriKey] ?? $item['url'] ?? $item['audio_url'] ?? ''));
                if ($uri === '') {
                    continue;
                }
                $asset = AiTaskResultAsset::where([
                    'consumption_id' => $consumptionId,
                    'asset_type' => $type,
                    'external_url' => $uri,
                ])->findOrEmpty();
                if ($asset->isEmpty()) {
                    $asset = AiTaskResultAsset::create([
                        'app_task_id' => (int)$consumption['app_task_id'],
                        'consumption_id' => $consumptionId,
                        'tenant_id' => (int)$consumption['tenant_id'],
                        'user_id' => (int)$consumption['user_id'],
                        'asset_type' => $type,
                        'external_url' => $uri,
                        'external_expire_time' => 0,
                        'local_uri' => self::isExternal($uri) ? '' : $uri,
                        'storage_scope' => (string)($item['storage_scope'] ?? ''),
                        'storage_engine' => (string)($item['storage_engine'] ?? ''),
                        'storage_domain' => (string)($item['storage_domain'] ?? ''),
                        'storage_meta' => [],
                        'transfer_status' => self::isExternal($uri) ? 'external' : 'stored',
                        'transfer_attempts' => 0,
                        'last_error' => '',
                        'create_time' => time(),
                        'update_time' => time(),
                    ]);
                }
                $assets[] = $asset->toArray();
                if ($forceTransfer || AiTaskResultStorageService::transferEnabled((int)$consumption['tenant_id'])) {
                    AiTaskJobService::enqueueTransfer((int)$asset['id'], $forceTransfer);
                }
            }
        }
        return $assets;
    }

    public static function transfer(int $assetId): void
    {
        $asset = AiTaskResultAsset::findOrEmpty($assetId);
        if ($asset->isEmpty() || (string)$asset['transfer_status'] === 'stored') {
            return;
        }
        $url = trim((string)$asset['external_url']);
        if (!self::isExternal($url)) {
            $asset->save(['local_uri' => $url, 'transfer_status' => 'stored', 'update_time' => time()]);
            return;
        }
        $tenantId = (int)$asset['tenant_id'];
        $userId = (int)$asset['user_id'];
        try {
            $stored = match ((string)$asset['asset_type']) {
                'image' => AigcImageAssetService::persistGeneratedImage($url, $tenantId, $userId),
                'video' => AigcVideoAssetService::persistGeneratedVideo($url, $tenantId, $userId),
                'audio' => AigcMusicAssetService::persistGeneratedAudio($url, $tenantId, $userId),
                default => throw new Exception('不支持的结果资源类型'),
            };
            $asset->save([
                'local_uri' => (string)($stored['uri'] ?? ''),
                'storage_scope' => (string)($stored['storage_scope'] ?? ''),
                'storage_engine' => (string)($stored['storage_engine'] ?? ''),
                'storage_domain' => (string)($stored['storage_domain'] ?? ''),
                'storage_meta' => $stored,
                'transfer_status' => 'stored',
                'transfer_attempts' => (int)$asset['transfer_attempts'] + 1,
                'last_error' => '',
                'update_time' => time(),
            ]);
        } catch (\Throwable $e) {
            $asset->save([
                'transfer_status' => 'retrying',
                'transfer_attempts' => (int)$asset['transfer_attempts'] + 1,
                'last_error' => mb_substr($e->getMessage(), 0, 1000),
                'update_time' => time(),
            ]);
            throw $e;
        }
    }

    private static function isExternal(string $uri): bool
    {
        return str_starts_with($uri, 'http://') || str_starts_with($uri, 'https://') || str_starts_with($uri, 'data:');
    }

    private static function arrayValue(mixed $value): array
    {
        if (is_array($value)) return $value;
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }
}
