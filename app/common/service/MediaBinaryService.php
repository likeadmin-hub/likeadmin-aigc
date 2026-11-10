<?php
namespace app\common\service;

/** Shared discovery for CLI and PHP-FPM, including Baota's versioned installations. */
final class MediaBinaryService
{
    public static function resolve(string $name, array $preferred = []): string
    {
        if (!in_array($name, ['ffmpeg', 'ffprobe'], true)) return '';
        $candidates = array_merge($preferred, self::configured($name), self::standard($name, false));
        if ($name === 'ffprobe') {
            // Baota may only link ffmpeg into PATH. Follow that link to the matching ffprobe.
            foreach (array_merge(self::configured('ffmpeg'), self::standard('ffmpeg')) as $ffmpeg) {
                $ffmpeg = self::executable((string)$ffmpeg);
                if ($ffmpeg !== '') $candidates[] = dirname((string)realpath($ffmpeg)) . '/ffprobe';
            }
        }
        $versioned = array_merge(
            @glob('/www/server/ffmpeg/*/' . $name) ?: [],
            @glob('/www/server/ffmpeg/*/bin/' . $name) ?: [],
            @glob('/www/server/panel/plugin/ffmpeg/*/' . $name) ?: []
        );
        rsort($versioned, SORT_NATURAL);
        foreach (array_merge($candidates, self::standard($name), $versioned) as $candidate) {
            $path = self::executable((string)$candidate);
            if ($path !== '') return $path;
        }
        return '';
    }

    private static function configured(string $name): array
    {
        return array_filter([
            (string)(getenv(strtoupper($name) . '_BINARY') ?: ''),
            (string)env($name . '_binary', ''), (string)env($name . '.binary', ''),
        ]);
    }

    private static function standard(string $name, bool $includeFixed = true): array
    {
        $paths = [root_path() . 'runtime/bin/' . $name];
        foreach (explode(PATH_SEPARATOR, (string)getenv('PATH')) as $dir) {
            if ($dir !== '' && (str_starts_with($dir, '/') || preg_match('~^[A-Za-z]:[\\\\/]~', $dir))) {
                $paths[] = rtrim($dir, '/\\') . '/' . $name;
            }
        }
        if (!$includeFixed) return $paths;
        foreach (['/usr/bin', '/usr/local/bin', '/opt/homebrew/bin', '/opt/local/bin',
            '/www/server/ffmpeg/bin', '/www/server/ffmpeg', '/usr/local/ffmpeg/bin',
            '/www/server/panel/plugin/ffmpeg/bin', '/www/server/panel/plugin/ffmpeg'] as $dir) $paths[] = $dir . '/' . $name;
        return $paths;
    }

    private static function executable(string $path): string
    {
        $path = trim(trim($path), "\"'");
        foreach ([$path, PHP_OS_FAMILY === 'Windows' ? $path . '.exe' : ''] as $candidate) {
            if ($candidate !== '' && @is_file($candidate) && @is_executable($candidate)) return $candidate;
        }
        return '';
    }
}
