<?php

namespace app\common\service\app;

use app\common\service\update\AppPackageUpdateService;
use app\common\service\update\SystemUpdateProtocol;
use app\common\service\update\UpdateLicenseService;
use app\common\service\update\UpdateSourceClient;
use think\cache\driver\File;
use Throwable;

final class AppCatalogArtwork
{
    private const FRESH_FOR = 21600;
    private const KEEP_FOR = 86400;
    private const RETRY_AFTER = 60;

    // Older installations can still have these stock covers in the app or tenant record.
    private const BUNDLED_VIDEO_COVERS = [
        'aigc_sale_video' => [],
        'aigc_depth_replication' => [],
        'aigc_video_replication' => ['resource/image/apps/aigc_short_drama/style-covers/cinematic.webp'],
    ];

    public static function bundledCoverUri(string $code, string $configuredUri): string
    {
        if (!isset(self::BUNDLED_VIDEO_COVERS[$code])) {
            return '';
        }
        $uri = 'resource/image/apps/' . $code . '/cover.webp';
        $defaults = ['', $uri, 'resource/image/common/menu_generator.png', ...self::BUNDLED_VIDEO_COVERS[$code]];
        $site = rtrim(request()->domain(), '/') . '/';
        if (str_starts_with($configuredUri, $site)) {
            $configuredUri = substr($configuredUri, strlen($site));
        }
        $configuredUri = ltrim($configuredUri, '/');
        if (preg_match('~^' . preg_quote($uri, '~') . '\?v=[0-9a-f]{12}$~D', $configuredUri)) {
            return $uri;
        }
        return in_array($configuredUri, $defaults, true) ? $uri : '';
    }

    public static function bundledCoverUrl(string $code, string $configuredUri): string
    {
        $uri = self::bundledCoverUri($code, $configuredUri);
        if ($uri === '') {
            return '';
        }
        $file = public_path() . $uri;
        $hash = is_file($file) && is_readable($file) ? hash_file('sha256', $file) : false;
        return $hash ? '/' . $uri . '?v=' . substr($hash, 0, 12) : '';
    }

    /** Same-app artwork for admin catalogs, even when the cloud cannot be reached. */
    public static function byCode(bool $refresh = true): array
    {
        $live = [];
        try {
            $key = self::cacheKey();
            if ($key !== '') {
                $cache = self::cache();
                $cached = (array)$cache->get($key, []);
                $live = (array)($cached['artwork'] ?? []);
                if ($refresh && (int)($cached['refresh_at'] ?? 0) <= time() && !$cache->get($key . ':retry')) {
                    $lock = fopen(root_path() . 'runtime/app-catalog-artwork.lock', 'c');
                    if ($lock !== false) {
                        try {
                            if (flock($lock, LOCK_EX | LOCK_NB)) {
                                $cached = (array)$cache->get($key, []);
                                $live = (array)($cached['artwork'] ?? []);
                                if ((int)($cached['refresh_at'] ?? 0) <= time() && !$cache->get($key . ':retry')) {
                                    $cache->set($key . ':retry', true, self::RETRY_AFTER);
                                    (new AppPackageUpdateService())->cloudLists([], 3);
                                    $live = (array)($cache->get($key, [])['artwork'] ?? []);
                                }
                            }
                        } finally {
                            fclose($lock);
                        }
                    }
                }
            }
        } catch (Throwable) {
            // The last successfully verified artwork is display-only.
        }
        return $live + (array)config('app_catalog_artwork', []);
    }

    public static function fromCloud(array $row, string $sourceUrl = ''): string
    {
        $latest = (array)($row['latest_version_info'] ?? []);
        $manifest = (array)($row['manifest'] ?? []);
        $latestManifest = (array)($latest['manifest'] ?? []);
        $source = parse_url($sourceUrl);
        $origin = !empty($source['scheme']) && !empty($source['host'])
            ? $source['scheme'] . '://' . $source['host'] . (isset($source['port']) ? ':' . $source['port'] : '')
            : '';
        foreach ([$row['cover_url'] ?? '', $row['cover'] ?? '', $latest['cover_url'] ?? '',
            $latest['cover'] ?? '', $manifest['cover_url'] ?? '', $manifest['cover'] ?? '',
            $latestManifest['cover_url'] ?? '', $latestManifest['cover'] ?? '',
            $row['icon_url'] ?? '', $row['icon'] ?? '', $latest['icon_url'] ?? '', $latest['icon'] ?? '',
            $manifest['icon'] ?? '', $latestManifest['icon'] ?? ''] as $value) {
            if (!is_string($value)) continue;
            $url = trim($value);
            if ($origin !== '' && preg_match('#^/?(?:uploads|resource|storage)/#', $url)) {
                $url = $origin . '/' . ltrim($url, '/');
            }
            $parts = parse_url($url);
            if (in_array(strtolower($parts['scheme'] ?? ''), ['https', 'http'], true)
                && !empty($parts['host']) && !isset($parts['user']) && !isset($parts['pass'])
                && strtolower(basename(rawurldecode($parts['path'] ?? ''))) !== 'menu_generator.png') {
                return $url;
            }
        }
        return '';
    }

    /** Only successful reads of the unfiltered cloud catalog populate the shared display cache. */
    public static function rememberCloudCatalog(string $key, array $artwork): void
    {
        try {
            if ($key !== '' && self::cacheKey() === $key) {
                self::cache()->set($key, ['artwork' => $artwork, 'refresh_at' => time() + self::FRESH_FOR], self::KEEP_FOR);
            }
        } catch (Throwable) {
            // The catalog remains available when the display cache is not writable.
        }
    }

    public static function cacheKey(): string
    {
        $source = UpdateSourceClient::appSource();
        if (empty($source['active_base_url']) || empty($source['active_api_key']) || empty($source['public_key'])) return '';
        $context = (new UpdateLicenseService())->verifiedSiteContext();
        if (empty($context['verified']) || $context['status'] !== 'active') return '';
        return SystemUpdateProtocol::contextKey($context, $source);
    }

    private static function cache(): File
    {
        return new File(app(), ['path' => root_path() . 'runtime/app-catalog-artwork']);
    }
}
