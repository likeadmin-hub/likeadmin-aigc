<?php

namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ShortDramaExportAudioCompatibilityTest extends TestCase
{
    public function testBgmFileExtensionKeepsCommonProviderAudioFormats(): void
    {
        self::assertSame('opus', $this->invoke('audioFileExtension', 'https://audio.example.test/result.opus?token=1'));
        self::assertSame('webm', $this->invoke('audioFileExtension', 'https://audio.example.test/result', 'audio/webm'));
        self::assertSame('m4a', $this->invoke('audioFileExtension', 'https://audio.example.test/result', 'audio/mp4'));
    }

    public function testOnlyHttpProviderUrisArePersistedAsRemoteAudio(): void
    {
        self::assertTrue($this->invoke('isRemoteHttpUrl', 'https://audio.example.test/result.mp3'));
        self::assertTrue($this->invoke('isRemoteHttpUrl', 'HTTP://audio.example.test/result.mp3'));
        self::assertFalse($this->invoke('isRemoteHttpUrl', 'uploads/aigc_short_drama/20260920/bgm.mp3'));
    }

    public function testBgmIsNormalizedToAacBeforeFinalMix(): void
    {
        $ffmpeg = $this->ffmpegBinary();
        if ($ffmpeg === '') {
            self::markTestSkipped('FFmpeg is not available in this test environment.');
        }
        $workDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'short_drama_bgm_' . bin2hex(random_bytes(6));
        self::assertTrue(@mkdir($workDir, 0775, true) || is_dir($workDir));
        $input = $workDir . DIRECTORY_SEPARATOR . 'provider-source.wav';
        $ffmpegCmd = $ffmpeg === 'ffmpeg' ? 'ffmpeg' : escapeshellarg($ffmpeg);
        try {
            $output = [];
            $code = 1;
            @exec($ffmpegCmd . ' -hide_banner -loglevel error -y -f lavfi -i ' . escapeshellarg('sine=frequency=440:duration=0.2') . ' -c:a pcm_s16le ' . escapeshellarg($input) . ' 2>&1', $output, $code);
            if ($code !== 0 || !is_file($input)) {
                self::markTestSkipped('FFmpeg cannot create a synthetic WAV fixture.');
            }

            $normalized = (string)$this->invoke('normalizeBgmForFfmpeg', $ffmpegCmd, $input, $workDir, 101);
            self::assertFileExists($normalized);
            self::assertGreaterThan(0, filesize($normalized));
            self::assertStringEndsWith('.m4a', $normalized);
        } finally {
            foreach (glob($workDir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($workDir);
        }
    }

    private function invoke(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(AigcShortDramaService::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs(null, $arguments);
    }

    private function ffmpegBinary(): string
    {
        if (!function_exists('exec')) {
            return '';
        }
        foreach (array_filter([
            (string)(getenv('FFMPEG_BINARY') ?: ''),
            '/usr/bin/ffmpeg',
            '/usr/local/bin/ffmpeg',
            '/opt/homebrew/bin/ffmpeg',
            'ffmpeg',
        ]) as $candidate) {
            $output = [];
            $code = 1;
            @exec(escapeshellarg($candidate) . ' -hide_banner -version 2>&1', $output, $code);
            if ($code === 0) {
                return $candidate;
            }
        }
        return '';
    }
}
