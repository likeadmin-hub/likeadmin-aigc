<?php

namespace app\common\service\case_gallery;

use app\common\service\FileService;
use think\facade\Cache;

/** Read-only, tenant-scoped palette for public gallery cards. Never accepts a client URL. */
class CasePaletteService
{
    private const MAX_BYTES = 16 * 1024 * 1024;

    public static function color(int $tenantId, int $id): string
    {
        if ($tenantId <= 0 || $id <= 0) return '';
        try {
            $case = CaseGalleryService::detail($tenantId, $id);
            if ((int)($case['status'] ?? 0) !== 1) return '';
            $uri = (string)($case['cover_path'] ?? '');
            if ($uri === '' && ($case['media_type'] ?? '') === 'image') $uri = (string)($case['media_path'] ?? '');
            if ($uri === '' || preg_match('~uploads/app_case/[^/]+/default/~', $uri)) return '';
            $url = FileService::getFileUrl($uri);
            $key = 'case_palette_v1:' . $tenantId . ':' . $id . ':' . sha1($url . ':' . ($case['update_time'] ?? ''));
            $cached = Cache::get($key);
            if (is_string($cached)) return $cached;
            $bytes = self::readImage($uri, $url);
            $color = $bytes === '' ? '' : self::sample($bytes);
            Cache::set($key, $color, $color === '' ? 300 : 86400);
            return $color;
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** Match card-util's final min(10% of height, 5px), every 50th pixel. */
    public static function sample(string $bytes): string
    {
        if (strlen($bytes) > self::MAX_BYTES || !function_exists('imagecreatefromstring')) return '';
        $size = @getimagesizefromstring($bytes);
        if (!$size || $size[0] * $size[1] > 20000000 || !in_array($size['mime'] ?? '', ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true)) return '';
        $image = @imagecreatefromstring($bytes);
        if (!$image) return '';
        $width = imagesx($image);
        $height = imagesy($image);
        $strip = max(1, min(5, (int)floor($height * .1)));
        $rgb = [0, 0, 0];
        $weight = 0;
        for ($i = 0; $i < $width * $strip; $i += 50) {
            $pixel = imagecolorsforindex($image, imagecolorat($image, $i % $width, $height - $strip + intdiv($i, $width)));
            $alpha = 1 - $pixel['alpha'] / 127;
            foreach (['red', 'green', 'blue'] as $index => $channel) $rgb[$index] += $pixel[$channel] * $alpha;
            $weight += $alpha;
        }
        imagedestroy($image);
        if (!$weight) return '';
        return 'rgb(' . implode(', ', array_map(static fn($n) => (int)round($n / $weight), $rgb)) . ')';
    }

    private static function readImage(string $uri, string $url): string
    {
        // Local files stay inside public/uploads, including after resolving symlinks.
        if (str_starts_with(ltrim($uri, '/'), 'uploads/')) {
            $root = realpath(public_path() . 'uploads');
            $path = realpath(public_path() . ltrim($uri, '/'));
            if ($root && $path && str_starts_with($path, $root . DIRECTORY_SEPARATOR) && is_file($path)) {
                return filesize($path) <= self::MAX_BYTES ? (string)file_get_contents($path) : '';
            }
        }
        $parts = parse_url($url);
        if (!$parts || !in_array($parts['scheme'] ?? '', ['https', 'http'], true) || isset($parts['user']) || isset($parts['pass'])) return '';
        $host = $parts['host'] ?? '';
        $port = (int)($parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80));
        if (!in_array($port, [80, 443], true)) return '';
        $ips = gethostbynamel($host) ?: [];
        if (!$ips) return '';
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return '';
        }
        $body = '';
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 8,
            // Pin the validated public address: no DNS rebinding or redirect to internal services.
            CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $ips[0]],
            CURLOPT_PROXY => '',
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > self::MAX_BYTES) return 0;
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        return $ok !== false && $status === 200 ? $body : '';
    }
}
