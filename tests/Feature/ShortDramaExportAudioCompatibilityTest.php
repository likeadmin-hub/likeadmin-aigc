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

    public function testNormalizedShotClipsKeepAudioAndVideoOnTheStoryboardClock(): void
    {
        $ffmpeg = $this->ffmpegBinary();
        if ($ffmpeg === '') {
            self::markTestSkipped('FFmpeg is not available in this test environment.');
        }
        $workDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'short_drama_timeline_' . bin2hex(random_bytes(6));
        self::assertTrue(@mkdir($workDir, 0775, true) || is_dir($workDir));
        $ffmpegCmd = $ffmpeg === 'ffmpeg' ? 'ffmpeg' : escapeshellarg($ffmpeg);
        $longSource = $workDir . DIRECTORY_SEPARATOR . 'provider-long.mp4';
        $shortSource = $workDir . DIRECTORY_SEPARATOR . 'provider-short-silent.mp4';
        try {
            $this->runFfmpeg(
                $ffmpegCmd . ' -hide_banner -loglevel error -y'
                . ' -f lavfi -i ' . escapeshellarg('color=c=red:s=320x180:r=30:d=1.4')
                . ' -f lavfi -i ' . escapeshellarg('sine=frequency=440:sample_rate=48000:duration=1.4')
                . ' -map 0:v:0 -map 1:a:0 -c:v libx264 -pix_fmt yuv420p -c:a aac ' . escapeshellarg($longSource)
            );
            $this->runFfmpeg(
                $ffmpegCmd . ' -hide_banner -loglevel error -y'
                . ' -f lavfi -i ' . escapeshellarg('color=c=blue:s=640x360:r=30:d=0.2')
                . ' -c:v libx264 -pix_fmt yuv420p -an ' . escapeshellarg($shortSource)
            );

            $ffprobe = (string)$this->invoke('resolveFfprobeBinary', $ffmpeg);
            $target = (array)$this->invoke('exportVideoTargetSpec', $ffmpegCmd, $ffprobe, $longSource);
            $first = (string)$this->invoke('normalizeExportClipForFfmpeg', $ffmpegCmd, $ffprobe, $longSource, $workDir, 1, 1.0, $target);
            $second = (string)$this->invoke('normalizeExportClipForFfmpeg', $ffmpegCmd, $ffprobe, $shortSource, $workDir, 2, 2.0, $target);
            $firstTiming = (array)$this->invoke('probeExportMedia', $ffmpegCmd, $ffprobe, $first);
            $secondTiming = (array)$this->invoke('probeExportMedia', $ffmpegCmd, $ffprobe, $second);
            self::assertTrue($firstTiming['has_audio']);
            self::assertTrue($secondTiming['has_audio']);
            self::assertEqualsWithDelta(1.0, (float)$firstTiming['video_duration'], 0.15);
            self::assertEqualsWithDelta(1.0, (float)$firstTiming['audio_duration'], 0.15);
            self::assertEqualsWithDelta(2.0, (float)$secondTiming['video_duration'], 0.15);
            self::assertEqualsWithDelta(2.0, (float)$secondTiming['audio_duration'], 0.15);

            $list = $workDir . DIRECTORY_SEPARATOR . 'concat.txt';
            file_put_contents($list, "file '" . str_replace("'", "'\\\\''", $first) . "'\nfile '" . str_replace("'", "'\\\\''", $second) . "'");
            $concat = $workDir . DIRECTORY_SEPARATOR . 'concat.mp4';
            $this->runFfmpeg($ffmpegCmd . ' -hide_banner -loglevel error -y -f concat -safe 0 -i ' . escapeshellarg($list) . ' -c copy ' . escapeshellarg($concat));
            $timing = (array)$this->invoke('assertExportMediaTiming', $ffmpegCmd, $ffprobe, $concat, 0.15);
            self::assertEqualsWithDelta(3.0, (float)$timing['duration'], 0.2);
            self::assertEqualsWithDelta((float)$timing['video_duration'], (float)$timing['audio_duration'], 0.15);

            $bgm = $workDir . DIRECTORY_SEPARATOR . 'short-bgm.m4a';
            $this->runFfmpeg($ffmpegCmd . ' -hide_banner -loglevel error -y -f lavfi -i ' . escapeshellarg('sine=frequency=220:sample_rate=48000:duration=0.25') . ' -c:a aac ' . escapeshellarg($bgm));
            $mixed = $workDir . DIRECTORY_SEPARATOR . 'mixed.mp4';
            $this->runFfmpeg(
                $ffmpegCmd . ' -hide_banner -loglevel error -y -i ' . escapeshellarg($concat)
                . ' -stream_loop -1 -i ' . escapeshellarg($bgm)
                . ' -filter_complex ' . escapeshellarg('[1:a]volume=0.18[bgm];[0:a][bgm]amix=inputs=2:duration=first:dropout_transition=0[a]')
                . ' -map 0:v:0 -map ' . escapeshellarg('[a]') . ' -c:v copy -c:a aac -shortest ' . escapeshellarg($mixed)
            );
            $mixedTiming = (array)$this->invoke('assertExportMediaTiming', $ffmpegCmd, $ffprobe, $mixed, 0.15);
            self::assertEqualsWithDelta(3.0, (float)$mixedTiming['duration'], 0.2);
            self::assertEqualsWithDelta((float)$mixedTiming['video_duration'], (float)$mixedTiming['audio_duration'], 0.15);
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

    private function runFfmpeg(string $command): void
    {
        $output = [];
        $code = 1;
        @exec($command . ' 2>&1', $output, $code);
        self::assertSame(0, $code, implode("\n", (array)$output));
    }
}
