<?php
namespace app\common\service\app\aigc_video;

use app\common\model\Config;
use app\common\service\FileService;

/** Platform-owned default previews, shared by tenants without copying their files. */
final class VideoEffectMediaService
{
    public const CONFIG_TYPE = 'aigc_video_default_media';

    public static function registry(): array
    {
        // Explicit platform model: request tenant context must never change ownership.
        $result = [];
        foreach (Config::where('type', self::CONFIG_TYPE)->column('value') as $value) {
            $entry = json_decode((string)$value, true);
            if (is_array($entry) && isset($entry['id'], $entry['files'])) $result[$entry['id']] = $entry['files'];
        }
        return $result;
    }

    public static function saveEntry(string $id, array $files): void
    {
        // One small row per effect avoids overflowing the shared TEXT config column.
        $query = ['type'=>self::CONFIG_TYPE, 'name'=>sha1($id)];
        $row = Config::where($query)->findOrEmpty();
        $value = json_encode(['id'=>$id, 'files'=>$files], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if ($row->isEmpty()) Config::create($query + ['value'=>$value]);
        else { $row->value = $value; $row->save(); }
    }

    public static function apply(array $rows, array $defaults): array
    {
        return self::resolve($rows, $defaults, self::registry(), static function(array $file): string {
            return FileService::getFileUrlByStorage($file['uri'], 'platform', $file['storage_engine'], $file['storage_domain']);
        });
    }

    /** Read-only fingerprints identify old defaults without shipping obsolete URLs. */
    private static function legacyHashes(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) return [];
        try {
            $decoded = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            // Keep the original defaults if a deployment replaces/removes the file.
            return [];
        }
        if (!is_array($decoded)) return [];
        $result = [];
        foreach ($decoded as $id => $files) {
            if (!is_array($files)) continue;
            foreach (['poster', 'video'] as $field) {
                if (isset($files[$field]) && is_string($files[$field]) && preg_match('/^[a-f0-9]{64}$/D', $files[$field])) {
                    $result[$id][$field] = $files[$field];
                }
            }
        }
        return $result;
    }

    public static function isDefaultPreview(array $default, string $field, string $url): bool
    {
        if ($url === '' || !in_array($field, ['poster', 'video'], true) || empty($default['id']) || empty($default[$field])) return false;
        if ($url === ($default[$field] ?? null)) return true;
        static $legacy;
        $legacy ??= self::legacyHashes(dirname(__DIR__, 4).'/apps/aigc_video/data/video_effect_legacy_media.json');
        $hash = $legacy[$default['id']][$field] ?? '';
        return $hash !== '' && hash_equals($hash, hash('sha256', $url));
    }

    public static function resolve(array $rows, array $defaults, array $registry, callable $url): array
    {
        $sources = array_column($defaults, null, 'id');
        foreach ($rows as &$row) {
            foreach (['poster','video'] as $field) {
                $default = $sources[$row['id']] ?? [];
                $current = (string)($row[$field] ?? '');
                // Convert only exact historical defaults; keep every custom URL and explicit blank.
                $isDefault = self::isDefaultPreview($default, $field, $current);
                if ($isDefault) $row[$field] = $default[$field];
                $file = $registry[$row['id']][$field] ?? null;
                // Preserve blank previews and every tenant custom URL or template reference.
                if (!$file || empty($file['uri']) || ($file['storage_scope'] ?? '') !== 'platform') continue;
                $path = ltrim((string)parse_url($current, PHP_URL_PATH), '/');
                $sameManagedFile = $path === ltrim($file['uri'], '/') && (
                    $file['storage_engine'] === 'local'
                    || parse_url($current, PHP_URL_HOST) === parse_url($file['storage_domain'], PHP_URL_HOST)
                );
                if (!$isDefault && !$sameManagedFile) continue;
                $row[$field] = $url($file);
            }
        }
        unset($row);
        return $rows;
    }
}
