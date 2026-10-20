<?php
namespace app\common\service\decorate;

use app\common\service\app\AppFrontendManifestService;
use app\common\service\FileService;
use think\facade\Db;

/** Read completed business results without refreshing providers or creating tasks. */
class DecorateWorkSourceService
{
    private const SOURCES = [
        'aigc_image' => ['aigc_image', 'image_uri', 'aigc_image', 'AI图片'],
        'aigc_video' => ['aigc_video', 'video_uri', 'aigc_video', 'AI视频'],
        'aigc_digital_human' => ['aigc_digital_human', 'video_uri', 'aigc_digital_human', '数字人视频'],
        'image_human' => ['image_human', 'video_uri', 'aigc_digital_human', '图片数字人'],
        'aigc_music' => ['aigc_music', 'audio_uri', 'aigc_music', 'AI音乐'],
    ];

    public static function lists(int $tenantId, int $userId, int $limit = 12): array
    {
        if ($tenantId <= 0 || $userId <= 0) return [];
        $limit = max(1, min(50, $limit));
        $entries = [];
        foreach (AppFrontendManifestService::tenantEntries($tenantId, 'uniapp') as $entry) {
            $entries[(string)($entry['app_code'] ?? '')] = (string)($entry['path'] ?? '');
        }
        $result = [];
        foreach (self::SOURCES as $code => [$table, $uriKey, $category, $name]) {
            if (empty($entries[$code])) continue;
            // Both sides are scoped: even a corrupt cross-tenant task_id cannot
            // join another person's result into this private feed.
            $rows = Db::name($table . '_result')->alias('r')
                ->join($table . '_task t', 't.id = r.task_id AND t.tenant_id = r.tenant_id AND t.user_id = r.user_id')
                ->where(['r.tenant_id' => $tenantId, 'r.user_id' => $userId, 'r.delete_time' => 0, 't.delete_time' => 0])
                ->whereIn('t.status', ['success', 'partial_failed'])
                ->field('r.*')->order('r.id', 'desc')->limit($limit)->select()->toArray();
            foreach ($rows as $row) {
                $url = self::url((string)($row[$uriKey] ?? ''), $row);
                if ($url === '') continue;
                $result[] = [
                    'id' => $code . ':' . $row['id'], 'title' => $name,
                    'image' => $uriKey === 'image_uri' ? $url : self::url((string)($row['cover_uri'] ?? ''), $row),
                    'media_url' => $url, 'app_code' => $code, 'category' => $category,
                    'create_time' => (int)($row['create_time'] ?? 0),
                    'link' => ['path' => $entries[$code], 'type' => 'shop', 'query' => []],
                ];
            }
        }
        usort($result, static fn($a, $b) => $b['create_time'] <=> $a['create_time'] ?: strcmp($b['id'], $a['id']));
        return array_slice($result, 0, $limit);
    }

    private static function url(string $uri, array $row): string
    {
        return $uri === '' ? '' : FileService::getFileUrlByStorage($uri, (string)($row['storage_scope'] ?? ''), (string)($row['storage_engine'] ?? ''), (string)($row['storage_domain'] ?? ''));
    }
}
