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
        return self::brandlessPreviews($rows, $defaults, self::managedPreviews(dirname(__DIR__, 4).'/apps/aigc_video/data/legacy_video_effect_media.json'), self::registry());
    }

    /** Only known reference/managed defaults are replaced; custom previews and blanks survive. */
    public static function brandlessPreviews(array $rows, array $defaults, array $legacy, array $registry): array
    {
        $sources = array_column($defaults, null, 'id');
        foreach ($rows as &$row) {
            foreach (['poster', 'video'] as $field) {
                $current = (string)($row[$field] ?? '');
                if ($current === '') continue;
                $file = $registry[$row['id']][$field] ?? [];
                $path = ltrim((string)parse_url($current, PHP_URL_PATH), '/');
                $managed = !empty($file['uri']) && ($file['storage_scope'] ?? '') === 'platform'
                    && $path === ltrim($file['uri'], '/')
                    && (($file['storage_engine'] ?? '') === 'local' || parse_url($current, PHP_URL_HOST) === parse_url($file['storage_domain'] ?? '', PHP_URL_HOST));
                if (\app\common\service\InspirationBrandService::isReferenceUrl($current)
                    || $current === ($legacy[$row['id']][$field] ?? null) || $managed) {
                    $row[$field] = $sources[$row['id']][$field] ?? ($field === 'poster' ? '/resource/image/inspiration/camera.svg' : '/resource/image/inspiration/camera.mp4');
                }
            }
        }
        unset($row);
        return $rows;
    }

    /** Optional display data must not prevent config reads or generation. */
    private static function managedPreviews(string $path): array
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
                if (isset($files[$field]) && is_string($files[$field]) && $files[$field] !== '') {
                    $result[$id][$field] = $files[$field];
                }
            }
        }
        return $result;
    }

    public static function resolve(array $rows, array $defaults, array $registry, callable $url): array
    {
        $sources = array_column($defaults, null, 'id');
        foreach ($rows as &$row) {
            foreach (['poster','video'] as $field) {
                $file = $registry[$row['id']][$field] ?? null;
                // Preserve blank previews and every tenant custom URL or template reference.
                if (!$file || empty($file['uri']) || ($file['storage_scope'] ?? '') !== 'platform') continue;
                $current = (string)($row[$field] ?? '');
                $path = ltrim((string)parse_url($current, PHP_URL_PATH), '/');
                $sameManagedFile = $path === ltrim($file['uri'], '/') && (
                    $file['storage_engine'] === 'local'
                    || parse_url($current, PHP_URL_HOST) === parse_url($file['storage_domain'], PHP_URL_HOST)
                );
                if ($current !== ($sources[$row['id']][$field] ?? null) && !$sameManagedFile) continue;
                $row[$field] = $url($file);
            }
        }
        unset($row);
        return $rows;
    }
}
