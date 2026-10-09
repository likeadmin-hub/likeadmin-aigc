<?php
namespace app\common\service\app\aigc_short_drama\vnext;

use RuntimeException;

/** Only inspect an uploaded local file, never a client URL or client-supplied duration. */
final class AudioMediaProbe
{
    public const VERSION = 'ffprobe-audio-v1';
    public const MAX_BYTES = 52428800;
    public const EXTENSIONS = ['mp3', 'wav', 'ogg', 'flac', 'aac', 'm4a', 'opus', 'webm'];

    public static function measure(string $path, string $extension, string $ffprobe): array
    {
        self::assertFile($path, $extension);
        if ($ffprobe === '' || !function_exists('exec')) throw new RuntimeException('当前服务暂时无法测量音频长度，请管理员检查 FFprobe 后重试', 409);
        $output = []; $code = 1;
        exec(escapeshellarg($ffprobe) . ' -v error -protocol_whitelist file,pipe -show_entries format=duration -show_entries stream=codec_type,duration -of json '
            . escapeshellarg($path) . ' 2>&1', $output, $code);
        $data = $code === 0 ? json_decode(implode("\n", $output), true) : null;
        return self::measurement(is_array($data) ? $data : []);
    }

    public static function assertFile(string $path, string $extension): void
    {
        clearstatcache(false, $path);
        if (!in_array($extension, self::EXTENSIONS, true) || !is_file($path) || !is_readable($path)
            || filesize($path) <= 0 || filesize($path) > self::MAX_BYTES) throw new RuntimeException('请上传不超过 50MB 的有效配音文件', 422);
    }

    public static function measurement(array $probe): array
    {
        $streams = $probe['streams'] ?? [];
        if (!is_array($streams) || count($streams) !== 1 || ($streams[0]['codec_type'] ?? '') !== 'audio') throw new RuntimeException('请上传只含一条声音轨的配音文件', 422);
        $duration = $streams[0]['duration'] ?? $probe['format']['duration'] ?? null;
        if (!is_numeric($duration) || (float)$duration <= 0) $duration = $probe['format']['duration'] ?? null;
        if (!is_numeric($duration) || !is_finite((float)$duration) || $duration <= 0 || $duration > 7200) throw new RuntimeException('配音长度无法确认或超过两小时，请检查文件', 422);
        return ['version' => self::VERSION, 'duration_seconds' => (float)$duration, 'audio_stream_count' => 1];
    }
}
