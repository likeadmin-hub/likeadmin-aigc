<?php

namespace app\common\service\app\aigc_canvas\agent\enrichment;

use Throwable;
use think\facade\Db;

/** Tenant-isolated cache for structured visual insight, never for original media. */
final class AssetInsightCacheService
{
    /** @return array<string, array<string, mixed>> */
    public static function find(int $tenantId, array $assets, string $modelVersion): array
    {
        $keys = array_values(array_unique(array_filter(array_map([self::class, 'assetKey'], $assets))));
        if ($tenantId <= 0 || $keys === []) return [];
        try {
            $rows = Db::name('aigc_canvas_asset_insight')
                ->where('tenant_id', $tenantId)
                ->where('model_code', $modelVersion)
                ->whereIn('asset_key', $keys)
                ->where('status', 'success')
                ->where('expire_time', '>', time())
                ->where('delete_time', 0)
                ->order('update_time', 'desc')
                ->select()
                ->toArray();
            $result = [];
            foreach ($rows as $row) {
                $key = (string)($row['asset_key'] ?? '');
                $decoded = self::decode($row['insight_json'] ?? []);
                if ($key !== '' && $decoded !== [] && !isset($result[$key])) {
                    $result[$key] = ProductFactPolicy::sanitizeInsight($decoded);
                }
            }
            return $result;
        } catch (Throwable) {
            return [];
        }
    }

    public static function store(int $tenantId, int $userId, int $projectId, array $insights, string $modelVersion, int $ttl): void
    {
        if ($tenantId <= 0 || $insights === []) return;
        $now = time();
        $expiry = $now + max(60, min(2592000, $ttl));
        foreach ($insights as $insight) {
            $insight = ProductFactPolicy::sanitizeInsight((array)$insight);
            $key = (string)($insight['asset_key'] ?? '');
            if ($key === '' || (float)($insight['confidence'] ?? 0) <= 0) continue;
            try {
                $existing = Db::name('aigc_canvas_asset_insight')
                    ->where(['tenant_id' => $tenantId, 'asset_key' => $key, 'model_code' => $modelVersion, 'delete_time' => 0])
                    ->find();
                $payload = [
                    'user_id' => $userId,
                    'project_id' => $projectId,
                    'asset_type' => (string)($insight['asset_type'] ?? 'image'),
                    'insight_json' => json_encode($insight, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'source_hash' => $key,
                    'status' => 'success',
                    'expire_time' => $expiry,
                    'update_time' => $now,
                    'delete_time' => 0,
                ];
                if ($existing) {
                    Db::name('aigc_canvas_asset_insight')->where('id', (int)$existing['id'])->update($payload);
                } else {
                    Db::name('aigc_canvas_asset_insight')->insert($payload + [
                        'tenant_id' => $tenantId,
                        'asset_key' => $key,
                        'asset_url' => '',
                        'model_code' => $modelVersion,
                        'create_time' => $now,
                    ]);
                }
            } catch (Throwable) {
                // Cache persistence is optional and must not fail the creative turn.
            }
        }
    }

    public static function assetKey($asset): string
    {
        $row = is_array($asset) ? $asset : ['url' => (string)$asset];
        $data = is_array($row['data'] ?? null) ? $row['data'] : [];
        $metadata = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];
        $url = trim((string)($row['url'] ?? $row['uri'] ?? $row['image_url'] ?? $row['file_url'] ?? $data['url'] ?? $data['image_url'] ?? $metadata['url'] ?? $metadata['image'] ?? ''));
        return trim((string)($row['asset_key'] ?? $row['id'] ?? $row['file_id'] ?? sha1($url . '|' . ($row['name'] ?? ''))));
    }

    private static function decode($value): array
    {
        if (is_array($value)) return $value;
        $decoded = json_decode((string)$value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
