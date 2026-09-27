<?php
namespace app\common\service\home;

use app\common\model\TenantConfig;
use app\common\service\FileService;
use RuntimeException;

class LatestNewsService
{
    public static function lists(int $tenantId, bool $public = false): array
    {
        if ($tenantId <= 0) { return []; }
        $raw = TenantConfig::where(['tenant_id' => $tenantId, 'type' => 'system_default', 'name' => 'latest_news'])->value('value');
        $rows = $raw === null ? json_decode(file_get_contents(__DIR__ . '/latest-news-defaults.json'), true) : json_decode($raw, true);
        $rows = is_array($rows) ? $rows : [];
        if ($public) { $rows = array_values(array_filter($rows, fn($row) => !empty($row['enabled']))); }
        foreach ($rows as &$row) {
            $row['media_url'] = FileService::getFileUrlByStorage($row['media_url'], $row['storage_scope'] ?? '', $row['storage_engine'] ?? '', $row['storage_domain'] ?? '');
            $row['poster'] = empty($row['poster']) ? '' : FileService::getFileUrl($row['poster']);
        }
        return $rows;
    }

    public static function save(int $tenantId, array $rows): void
    {
        if ($tenantId <= 0) { throw new RuntimeException('租户不存在'); }
        if (count($rows) > 100) { throw new RuntimeException('最多配置100条动态'); }
        $clean = [];
        foreach ($rows as $row) {
            $title = trim((string)($row['title'] ?? ''));
            $media = trim((string)($row['media_url'] ?? ''));
            $type = (string)($row['media_type'] ?? 'image');
            $link = $row['link'] ?? [];
            $path = trim((string)($link['path'] ?? ''));
            $kind = (string)($link['kind'] ?? 'internal');
            if (!$title || mb_strlen($title) > 120) { throw new RuntimeException('标题必填且不能超过120字'); }
            if (!in_array($type, ['image', 'video', 'file'], true) || !self::safeMedia($media)) { throw new RuntimeException('请选择有效素材'); }
            if ($kind === 'external') {
                if (!preg_match('~^https?://~i', $path) || !filter_var($path, FILTER_VALIDATE_URL)) { throw new RuntimeException('外部链接必须为完整的http或https地址'); }
            } elseif ($kind !== 'internal' || !preg_match('~^/(?!/)[^\\\\\x00-\x20]*$~', $path)) {
                throw new RuntimeException('请选择有效的站内页面');
            }
            $poster = trim((string)($row['poster'] ?? ''));
            if ($poster !== '' && !self::safeMedia($poster)) { throw new RuntimeException('封面地址无效'); }
            $clean[] = [
                'id' => substr((string)($row['id'] ?? bin2hex(random_bytes(8))), 0, 64),
                'title' => $title, 'media_url' => $media, 'media_type' => $type, 'poster' => $poster,
                'enabled' => empty($row['enabled']) ? 0 : 1,
                'link' => ['kind' => $kind, 'path' => $path],
                'storage_scope' => substr((string)($row['storage_scope'] ?? ''), 0, 32),
                'storage_engine' => substr((string)($row['storage_engine'] ?? ''), 0, 32),
                'storage_domain' => substr((string)($row['storage_domain'] ?? ''), 0, 255),
            ];
        }
        $where = ['tenant_id' => $tenantId, 'type' => 'system_default', 'name' => 'latest_news'];
        $record = TenantConfig::where($where)->findOrEmpty();
        $value = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($record->isEmpty()) { TenantConfig::create($where + ['value' => $value]); }
        else { $record->save(['value' => $value]); }
    }

    private static function safeMedia(string $url): bool
    {
        return $url !== '' && !preg_match('~[\\\\\x00-\x20]~', $url) && (preg_match('~^https?://~i', $url) ? (bool)filter_var($url, FILTER_VALIDATE_URL) : (bool)preg_match('~^/?(?:uploads|storage|resource)/~', $url));
    }
}
