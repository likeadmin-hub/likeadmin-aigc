<?php
namespace app\common\service\app\aigc_digital_human;

use app\common\service\MediaDurationService;
use app\common\service\open_platform\OpenSafeHttp;

/** Generation billing measurements only; clone validation retains its existing whole-second rules. */
final class AvatarGenerationDurationService
{
    /** Existing narration estimate: four characters per second, rounded up. Never final usage. */
    public static function estimateScriptSeconds(string $text): int
    {
        return max(1, (int)ceil(mb_strlen($text) / 4));
    }

    public static function detect(string $uri): float
    {
        if ($uri === '') return 0;
        $remote = preg_match('~^https?://~i', $uri) === 1;
        if (!$remote) {
            $path = ltrim($uri, '/');
            foreach ([public_path().$path, root_path().'public/'.$path, root_path().$path] as $file) {
                if (is_file($file)) return self::valid(MediaDurationService::detect($file));
            }
            return 0;
        }
        $path = null;
        try {
            $body = OpenSafeHttp::get($uri, 52428800, 25)['body'];
            $extension = strtolower(pathinfo((string)parse_url($uri, PHP_URL_PATH), PATHINFO_EXTENSION));
            if (substr($body, 0, 4) === 'RIFF' && substr($body, 8, 4) === 'WAVE') $extension = 'wav';
            $temp = tempnam(sys_get_temp_dir(), 'avatar-duration-');
            if ($temp === false) return 0;
            $path = $temp.'.'.(in_array($extension, ['wav','mp3','m4a','aac','ogg','opus','flac','webm'], true) ? $extension : 'audio');
            if (!rename($temp, $path)) { @unlink($temp); return 0; }
            if (file_put_contents($path, $body) !== strlen($body)) return 0;
            return self::valid(MediaDurationService::detect($path));
        } catch (\Throwable) {
            return 0;
        } finally {
            if ($path !== null) @unlink($path);
        }
    }

    private static function valid(float $seconds): float
    {
        return is_finite($seconds) && $seconds > 0 ? $seconds : 0;
    }
}
