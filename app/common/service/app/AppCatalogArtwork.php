<?php

namespace app\common\service\app;

use app\common\model\update\UpdateSource;
use think\cache\driver\File;
use Throwable;

final class AppCatalogArtwork
{
    /** Same-app artwork for admin catalogs, even when the cloud cannot be reached. */
    public static function byCode(): array
    {
        try {
            $key = self::cacheKey();
            $live = $key === '' ? [] : (array)self::cache()->get($key, []);
        } catch (Throwable) {
            $live = [];
        }
        return $live + (array)config('app_catalog_artwork', []);
    }

    public static function fromCloud(array $row): string
    {
        $latest = $row['latest_version_info'] ?? [];
        $manifest = $row['manifest'] ?? $latest['manifest'] ?? [];
        foreach ([$row['cover_url'] ?? '', $row['cover'] ?? '', $latest['cover_url'] ?? '',
            $latest['cover'] ?? '', $manifest['cover'] ?? '', $row['icon_url'] ?? '', $row['icon'] ?? '',
            $latest['icon_url'] ?? '', $latest['icon'] ?? ''] as $value) {
            if (!is_string($value)) continue;
            $url = trim($value);
            $parts = parse_url($url);
            if (in_array(strtolower($parts['scheme'] ?? ''), ['https', 'http'], true)
                && !empty($parts['host']) && !isset($parts['user']) && !isset($parts['pass'])
                && basename($parts['path'] ?? '') !== 'menu_generator.png') {
                return $url;
            }
        }
        return '';
    }

    /** Only successful reads of the unfiltered cloud catalog populate the shared display cache. */
    public static function rememberCloudCatalog(array $artwork): void
    {
        try {
            $key = self::cacheKey();
            if ($key !== '' && $artwork !== []) self::cache()->set($key, $artwork, 21600);
        } catch (Throwable) {
            // The catalog remains available when the display cache is not writable.
        }
    }

    private static function cacheKey(): string
    {
        $source = UpdateSource::where('status', 1)->order('id', 'desc')->field('id,update_time,public_key')->findOrEmpty();
        if ($source->isEmpty() || empty($source['public_key'])) return '';
        return hash('sha256', implode("\n", [$source['id'], $source['update_time'], $source['public_key']]));
    }

    private static function cache(): File
    {
        return new File(app(), ['path' => root_path() . 'runtime/cache/app-catalog-artwork']);
    }
}
