<?php

namespace app\common\service\app\aigc_video;

use app\common\service\FileService;
use app\common\service\storage\Driver as StorageDriver;
use app\common\service\storage\StorageConfigService;
use Exception;
use think\facade\Log;

/**
 * Produces a durable still image for a stored video.
 *
 * This is deliberately server-side: a canvas preview must not depend on a
 * browser being able to stream (or seek) the object-store video URL.
 */
class AigcVideoPosterService
{
    public static function createFirstFrame(
        int $tenantId,
        string $uri,
        string $storageScope = '',
        string $storageEngine = '',
        string $storageDomain = ''
    ): array {
        return self::createFrame($tenantId, $uri, $storageScope, $storageEngine, $storageDomain, 0.001, 'posters');
    }

    /**
     * Extract a still at a validated media time and save it through tenant storage.
     *
     * The caller supplies only a storage URI that it has already authorized; this
     * service never accepts arbitrary web URLs, which keeps frame extraction from
     * becoming a server-side fetch primitive.
     */
    public static function createFrame(
        int $tenantId,
        string $uri,
        string $storageScope = '',
        string $storageEngine = '',
        string $storageDomain = '',
        float $seconds = 0.001,
        string $directory = 'frames'
    ): array {
        $uri = self::canonicalUri($uri);
        if ($uri === '') {
            throw new Exception('视频地址无效，无法截帧');
        }
        // Older canvas records predate per-file storage metadata. Resolve that
        // absence once in the worker from the tenant's effective configuration,
        // never from a browser URL or request-local host.
        if ($storageEngine === '') {
            $sourceConfig = StorageConfigService::getEffectiveConfig($tenantId);
            $storageScope = (string)($sourceConfig['scope'] ?? 'tenant');
            $storageEngine = (string)($sourceConfig['default'] ?? 'local');
            $storageDomain = StorageConfigService::getStorageDomain($sourceConfig);
        }
        $ffmpeg = self::resolveFfmpegBinary();
        if ($ffmpeg === '') {
            throw new Exception('服务器未配置 FFmpeg，无法生成视频首帧');
        }

        $workDir = runtime_path() . 'aigc_video_frame_' . $tenantId . '_' . time() . '_' . random_int(1000, 9999) . DIRECTORY_SEPARATOR;
        if (!is_dir($workDir)) {
            @mkdir($workDir, 0775, true);
        }
        if (!is_dir($workDir) || !is_writable($workDir)) {
            throw new Exception('视频截帧临时目录不可用');
        }

        try {
            $sourcePath = self::localPublicFilePath($uri, $storageEngine);
            if ($sourcePath === '') {
                $url = FileService::getFileUrlByStorage($uri, $storageScope, $storageEngine, $storageDomain);
                $sourcePath = self::downloadVideo($url, $workDir, pathinfo($uri, PATHINFO_EXTENSION));
            }
            $framePath = $workDir . 'frame.jpg';
            self::extractFrame($ffmpeg, $sourcePath, $framePath, $seconds);
            $imageSize = @getimagesize($framePath) ?: [];
            $stored = self::storeImage($tenantId, $framePath, $directory);
            return $stored + [
                'width' => (int)($imageSize[0] ?? 0),
                'height' => (int)($imageSize[1] ?? 0),
            ];
        } finally {
            self::removeRuntimeDirectory($workDir);
        }
    }

    private static function canonicalUri(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $value) === 1) {
            $value = ltrim(rawurldecode((string)(parse_url($value, PHP_URL_PATH) ?: '')), '/');
        }
        $value = ltrim($value, '/');
        if (!str_starts_with($value, 'uploads/') && !str_starts_with($value, 'resource/')) {
            return '';
        }
        return $value;
    }

    private static function localPublicFilePath(string $uri, string $storageEngine): string
    {
        if ($storageEngine !== '' && $storageEngine !== 'local') {
            return '';
        }
        $path = public_path() . ltrim($uri, '/');
        return is_file($path) && is_readable($path) ? $path : '';
    }

    private static function downloadVideo(string $url, string $workDir, string $extension): string
    {
        if (preg_match('#^https?://#i', $url) !== 1) {
            throw new Exception('视频存储地址不可访问');
        }
        $extension = strtolower(trim($extension));
        if (!in_array($extension, ['mp4', 'webm', 'mov', 'm4v'], true)) {
            $extension = 'mp4';
        }
        $target = $workDir . 'source.' . $extension;
        $context = stream_context_create([
            'http' => ['timeout' => 90, 'follow_location' => 1, 'ignore_errors' => true, 'header' => "User-Agent: LikeAdminAigcPoster/1.0\r\n"],
            'https' => ['timeout' => 90, 'follow_location' => 1, 'ignore_errors' => true, 'header' => "User-Agent: LikeAdminAigcPoster/1.0\r\n"],
        ]);
        $read = @fopen($url, 'rb', false, $context);
        if (!$read) {
            throw new Exception('视频下载失败');
        }
        $write = @fopen($target, 'wb');
        if (!$write) {
            fclose($read);
            throw new Exception('视频首帧临时文件创建失败');
        }
        $copied = 0;
        $maxBytes = 1536 * 1024 * 1024;
        while (!feof($read)) {
            $chunk = fread($read, 1024 * 1024);
            if ($chunk === false) {
                fclose($read);
                fclose($write);
                throw new Exception('视频下载读取失败');
            }
            $copied += strlen($chunk);
            if ($copied > $maxBytes) {
                fclose($read);
                fclose($write);
                @unlink($target);
                throw new Exception('视频文件过大，无法生成首帧');
            }
            if ($chunk !== '' && fwrite($write, $chunk) === false) {
                fclose($read);
                fclose($write);
                throw new Exception('视频首帧临时文件写入失败');
            }
        }
        fclose($read);
        fclose($write);
        if (!is_file($target) || (int)filesize($target) <= 0) {
            throw new Exception('视频文件为空');
        }
        return $target;
    }

    private static function extractFrame(string $ffmpeg, string $sourcePath, string $framePath, float $seconds): void
    {
        $binary = $ffmpeg === 'ffmpeg' ? 'ffmpeg' : escapeshellarg($ffmpeg);
        $seconds = max(0.001, min(28800, $seconds));
        // Keep -ss after the input: this decodes the requested frame rather
        // than selecting a preceding keyframe on long-GOP videos.
        $commands = [
            $binary . ' -y -i ' . escapeshellarg($sourcePath) . ' -ss ' . escapeshellarg(sprintf('%.3F', $seconds)) . ' -frames:v 1 -q:v 2 ' . escapeshellarg($framePath) . ' 2>&1',
        ];
        // A first-frame retry without seeking is safe. For a user-selected
        // current/last frame it would silently return the wrong image instead.
        if ($seconds <= 0.001) {
            $commands[] = $binary . ' -y -i ' . escapeshellarg($sourcePath) . ' -frames:v 1 -q:v 2 ' . escapeshellarg($framePath) . ' 2>&1';
        }
        $lastOutput = [];
        foreach ($commands as $command) {
            @unlink($framePath);
            $output = [];
            $code = 1;
            $code = self::runCommand($command, $framePath . '.log');
            $output = is_file($framePath . '.log')
                ? (array)@file($framePath . '.log', FILE_IGNORE_NEW_LINES)
                : [];
            if ($code === 0 && is_file($framePath) && (int)filesize($framePath) > 0) {
                return;
            }
            $lastOutput = $output;
        }
        Log::write('AIGC video poster FFmpeg output: ' . implode("\n", (array)$lastOutput), 'error');
        throw new Exception('FFmpeg 视频截帧失败');
    }

    private static function runCommand(string $command, string $logPath): int
    {
        $process = @proc_open($command, [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $logPath, 'a'],
            2 => ['file', $logPath, 'a'],
        ], $pipes);
        if (!is_resource($process)) {
            return 1;
        }
        $deadline = microtime(true) + 120;
        do {
            $status = proc_get_status($process);
            if (!$status['running']) {
                $exitCode = (int)$status['exitcode'];
                @proc_close($process);
                return $exitCode;
            }
            if (microtime(true) >= $deadline) {
                @proc_terminate($process, 15);
                usleep(300000);
                @proc_terminate($process, 9);
                @proc_close($process);
                return 124;
            }
            usleep(100000);
        } while (true);
    }

    private static function storeImage(int $tenantId, string $framePath, string $directory): array
    {
        $config = StorageConfigService::getEffectiveConfig($tenantId);
        $driver = new StorageDriver($config);
        $driver->setUploadFileByReal($framePath);
        $directory = trim($directory, '/');
        $directory = in_array($directory, ['posters', 'frames'], true) ? $directory : 'frames';
        $saveDir = 'uploads/aigc_video/' . $directory . '/' . date('Ymd');
        if (!$driver->upload($saveDir)) {
            throw new Exception((string)$driver->getError());
        }
        $info = (array)$driver->getFileInfo();
        $fileSize = (int)($info['size'] ?? 0);
        if ($fileSize <= 0) {
            $fileSize = (int)(filesize($framePath) ?: 0);
        }
        return [
            'uri' => $saveDir . '/' . $driver->getFileName(),
            'storage_scope' => (string)($config['scope'] ?? 'tenant'),
            'storage_engine' => (string)($config['default'] ?? 'local'),
            'storage_domain' => StorageConfigService::getEffectiveDomain($tenantId),
            'mime_type' => (string)($info['mime'] ?? 'image/jpeg'),
            'file_size' => $fileSize,
        ];
    }

    private static function resolveFfmpegBinary(): string
    {
        if (!function_exists('exec')) {
            return '';
        }
        $candidates = [
            (string)env('ffmpeg_binary', ''),
            (string)env('ffmpeg.binary', ''),
            (string)(getenv('FFMPEG_BINARY') ?: ''),
            'ffmpeg',
        ];
        foreach (array_unique(array_filter(array_map(static fn($item): string => trim(trim((string)$item), "\"'"), $candidates))) as $candidate) {
            $output = [];
            $code = 1;
            @\exec(escapeshellarg($candidate) . ' -hide_banner -version 2>&1', $output, $code);
            if ($code === 0 && str_contains(strtolower(implode("\n", $output)), 'ffmpeg version')) {
                return $candidate;
            }
        }
        return '';
    }

    private static function removeRuntimeDirectory(string $dir): void
    {
        if ($dir === '' || !is_dir($dir) || !str_starts_with(str_replace('\\', '/', $dir), str_replace('\\', '/', runtime_path()))) {
            return;
        }
        foreach ((array)@scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                self::removeRuntimeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
