<?php
namespace app\common\service;

use app\common\model\file\File as UploadFile;
use app\common\model\file\TenantFile;
use app\common\service\open_platform\OpenSafeHttp;

/** Measure audio bytes using immutable storage metadata; client durations are never evidence. */
final class AudioDurationService
{
    public static function detect(string $uri, int $tenantId = 0, array $storage = []): float
    {
        if ($uri === '') return 0;
        $remote = preg_match('~^https?://~i', $uri) === 1;
        if (!$remote && $storage === []) {
            $key = ltrim($uri, '/');
            $row = TenantFile::where(['tenant_id'=>$tenantId, 'uri'=>$key])->findOrEmpty();
            if ($row->isEmpty()) $row = UploadFile::where(['uri'=>$key])->findOrEmpty();
            if (!$row->isEmpty()) $storage = $row->toArray();
        }
        // A stale local file with the same key must not shadow an explicitly stored cloud object.
        $engine = (string)($storage['storage_engine'] ?? '');
        $cloud = ($engine !== '' && $engine !== 'local') || ($engine === '' && !empty($storage['storage_domain']));
        if (!$cloud && (!$remote || $storage === [])) {
            $seconds = self::detectUri($uri);
            if ($seconds > 0 || $remote) return $seconds;
        }
        if ($storage === []) return 0;
        $url = FileService::getFileUrlByStorage($uri,
            (string)($storage['storage_scope'] ?? ''), (string)($storage['storage_engine'] ?? ''),
            (string)($storage['storage_domain'] ?? ''), $tenantId);
        return $url !== '' ? self::detectUri($url) : 0;
    }

    /** No database lookup; also used when only a full public URL or local URI is available. */
    public static function detectUri(string $uri): float
    {
        if ($uri === '') return 0;
        if (!preg_match('~^https?://~i', $uri)) {
            $path = ltrim($uri, '/');
            foreach ([public_path().$path, root_path().'public/'.$path, root_path().$path] as $file) {
                if (@is_file($file)) return self::valid(MediaDurationService::detect($file));
            }
            return 0;
        }
        $path = null;
        try {
            $body = OpenSafeHttp::get($uri, 52428800, 25)['body'];
            $ext = strtolower(pathinfo((string)parse_url($uri, PHP_URL_PATH), PATHINFO_EXTENSION));
            if (substr($body, 0, 4) === 'RIFF' && substr($body, 8, 4) === 'WAVE') $ext = 'wav';
            $temp = tempnam(sys_get_temp_dir(), 'audio-duration-');
            if ($temp === false) return 0;
            $path = $temp . '.' . (in_array($ext, ['wav','mp3','m4a','aac','ogg','opus','flac','webm'], true) ? $ext : 'audio');
            if (!rename($temp, $path)) { @unlink($temp); return 0; }
            if (file_put_contents($path, $body) !== strlen($body)) return 0;
            return self::valid(MediaDurationService::detect($path));
        } catch (\Throwable) { return 0; }
        finally { if ($path !== null) @unlink($path); }
    }

    private static function valid(float $seconds): float
    {
        return is_finite($seconds) && $seconds > 0 ? $seconds : 0;
    }
}
