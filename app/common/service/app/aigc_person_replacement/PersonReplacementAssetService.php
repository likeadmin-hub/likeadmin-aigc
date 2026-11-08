<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_person_replacement;

use app\common\service\app\aigc_image\AigcImageAssetService;
use app\common\service\app\aigc_video\AigcVideoAssetService;
use app\common\service\storage\StorageConfigService;
use RuntimeException;
use think\facade\Db;
use think\facade\Log;

/** Managed video and cover share one immutable storage selection. */
final class PersonReplacementAssetService
{
    public static function coverUrl(array $response): string
    {
        foreach (['data.result.data', 'data.result', 'data', 'result.data', 'result', ''] as $path) {
            $node = $response;
            foreach ($path === '' ? [] : explode('.', $path) as $key) $node = is_array($node) ? ($node[$key] ?? []) : [];
            if (!is_array($node)) continue;
            foreach (['cover_url', 'poster_url', 'thumbnail_url', 'cover', 'poster', 'thumbnail'] as $key) {
                $url = is_string($node[$key] ?? null) ? trim($node[$key]) : '';
                if (preg_match('#^https?://#i', $url) || str_starts_with($url, 'data:image/')) return $url;
            }
        }
        return '';
    }

    public static function persistVideo(string $url, array $response, int $tenant, int $user): array
    {
        $storage = StorageConfigService::getEffectiveConfig($tenant);
        $video = AigcVideoAssetService::persistGeneratedVideo($url, $tenant, $user, $storage);
        $cover = [];
        try { $cover = self::persistCover($response, $tenant, $user, $storage); }
        catch (\Throwable $error) {
            // Never fail/refund a successful video because its thumbnail needs a retry.
            Log::warning('person_replacement cover persistence failed', ['tenant_id'=>$tenant, 'user_id'=>$user, 'error_type'=>get_class($error)]);
        }
        return $video + ['cover_uri' => (string)($cover['uri'] ?? '')];
    }

    public static function persistCover(array $response, int $tenant, int $user, array $storage): array
    {
        $url = self::coverUrl($response);
        if ($url === '') return [];
        $cover = AigcImageAssetService::persistGeneratedImage($url, $tenant, $user, true, 16777216, $storage);
        if (empty($cover['stored']) || empty($cover['uri'])) throw new RuntimeException('封面未保存到素材存储');
        return $cover;
    }

    /** Default audit mode never downloads, uploads, updates a row or calls a supplier. */
    public static function repairCover(int $tenant, int $id, bool $apply = false): array
    {
        if ($tenant <= 0 || $id <= 0) throw new RuntimeException('请指定有效的租户和作品');
        $row = self::result($tenant, $id);
        $state = self::repairState($row);
        if ($state !== 'ready' || !$apply) return ['result_id'=>$id, 'status'=>$state === 'ready' ? 'would_repair' : $state];
        $lock = fopen(runtime_path().'person_replacement_cover_'.$tenant.'_'.$id.'.lock', 'c');
        if (!$lock) throw new RuntimeException('无法锁定封面补齐任务');
        if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); return ['result_id'=>$id, 'status'=>'busy']; }
        try {
            $row = self::result($tenant, $id);
            $state = self::repairState($row);
            if ($state !== 'ready') return ['result_id'=>$id, 'status'=>$state];
            $storage = self::storedConfig($row);
            // Network work is outside the database transaction and its row locks.
            $cover = self::persistCover(['cover_url'=>self::savedCoverUrl($row)], $tenant, (int)$row['user_id'], $storage);
            $updated = Db::transaction(function () use ($tenant, $id, $row, $cover): bool {
                $current = Db::name('aigc_person_replacement_result')->where(['tenant_id'=>$tenant,'id'=>$id,'user_id'=>$row['user_id'],'delete_time'=>0])->lock(true)->find();
                if (!$current || trim((string)($current['cover_uri'] ?? '')) !== '' || $current['video_uri'] !== $row['video_uri']) return false;
                $task = Db::name('aigc_person_replacement_task')->where(['tenant_id'=>$tenant,'id'=>$current['task_id'],'user_id'=>$current['user_id'],'status'=>'success','delete_time'=>0])->find();
                if (!$task) return false;
                return Db::name('aigc_person_replacement_result')->where(['tenant_id'=>$tenant,'id'=>$id,'user_id'=>$row['user_id']])->update(['cover_uri'=>$cover['uri'],'update_time'=>time()]) === 1;
            });
            return ['result_id'=>$id, 'status'=>$updated ? 'repaired' : 'changed'];
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private static function result(int $tenant, int $id): array
    {
        return Db::name('aigc_person_replacement_result')->alias('r')
            ->join('aigc_person_replacement_task t', 't.id=r.task_id AND t.tenant_id=r.tenant_id AND t.user_id=r.user_id')
            ->where(['r.tenant_id'=>$tenant,'r.id'=>$id,'r.delete_time'=>0,'t.delete_time'=>0,'t.status'=>'success'])
            ->field('r.*,t.provider_response AS task_response')->find() ?: [];
    }

    private static function repairState(array $row): string
    {
        if (!$row) return 'not_found';
        if (trim((string)($row['cover_uri'] ?? '')) !== '') return 'already_present';
        return self::savedCoverUrl($row) !== '' ? 'ready' : 'no_saved_cover';
    }

    private static function savedCoverUrl(array $row): string
    {
        foreach (['result_json', 'task_response'] as $key) {
            $raw = $row[$key] ?? [];
            $data = is_array($raw) ? $raw : json_decode((string)$raw, true);
            $url = self::coverUrl(is_array($data) ? $data : []);
            if ($url !== '') return $url;
        }
        return '';
    }

    private static function storedConfig(array $row): array
    {
        $config = StorageConfigService::getStoredFileConfig((int)$row['tenant_id'], $row['storage_scope'] ?? '', $row['storage_engine'] ?? '');
        foreach (['storage_scope'=>'scope','storage_engine'=>'default'] as $field=>$key) {
            if (!empty($row[$field]) && $row[$field] !== ($config[$key] ?? '')) throw new RuntimeException('作品存储配置已变化，请核对后重试');
        }
        if (($config['default'] ?? '') !== 'local' && !empty($row['storage_domain'])
            && rtrim($row['storage_domain'], '/') !== rtrim(StorageConfigService::getStorageDomain($config), '/')) {
            throw new RuntimeException('作品存储域名已变化，请核对后重试');
        }
        return $config;
    }
}
