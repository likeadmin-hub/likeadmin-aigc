<?php

namespace app\common\service;

class MediaDurationService
{
    public static function detect(string $filePath): float
    {
        if ($filePath === '' || !is_file($filePath)) {
            return 0;
        }
        $duration = self::probe($filePath);
        if ($duration > 0) {
            return $duration;
        }
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if ($ext === 'mp3' || self::looksLikeMp3($filePath)) {
            return self::estimateMp3($filePath);
        }
        if ($ext === 'wav') {
            return self::estimateWav($filePath);
        }
        return 0;
    }

    private static function probe(string $filePath): float
    {
        if (!function_exists('shell_exec')) {
            return 0;
        }
        $command = 'ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 ' . escapeshellarg($filePath) . ' 2>/dev/null';
        $output = trim((string)@shell_exec($command));
        if ($output === '' || !is_numeric($output)) {
            return 0;
        }
        return round(max(0, (float)$output), 2);
    }

    private static function looksLikeMp3(string $filePath): bool
    {
        $header = @file_get_contents($filePath, false, null, 0, 10);
        if ($header === false || strlen($header) < 4) {
            return false;
        }
        return substr($header, 0, 3) === 'ID3'
            || (ord($header[0]) === 0xff && (ord($header[1]) & 0xe0) === 0xe0);
    }

    private static function estimateMp3(string $filePath): float
    {
        $content = @file_get_contents($filePath);
        if ($content === false || strlen($content) < 4) {
            return 0;
        }
        $offset = self::skipId3Tag($content);
        $length = strlen($content);
        $duration = 0.0;
        $frames = 0;
        while ($offset + 4 <= $length) {
            if (ord($content[$offset]) !== 0xff || (ord($content[$offset + 1]) & 0xe0) !== 0xe0) {
                $offset++;
                continue;
            }
            $frame = self::parseMp3FrameHeader(substr($content, $offset, 4));
            if (empty($frame) || $frame['frame_length'] <= 0 || $offset + $frame['frame_length'] > $length + 1024) {
                $offset++;
                continue;
            }
            $duration += $frame['samples'] / $frame['sample_rate'];
            $frames++;
            $offset += $frame['frame_length'];
        }
        if ($frames > 0 && $duration > 0) {
            return round($duration, 2);
        }
        return self::estimateMp3ByFirstFrame($content, $filePath);
    }

    private static function skipId3Tag(string $content): int
    {
        if (strlen($content) < 10 || substr($content, 0, 3) !== 'ID3') {
            return 0;
        }
        $size = ((ord($content[6]) & 0x7f) << 21) | ((ord($content[7]) & 0x7f) << 14) | ((ord($content[8]) & 0x7f) << 7) | (ord($content[9]) & 0x7f);
        return $size + 10;
    }

    private static function parseMp3FrameHeader(string $header): array
    {
        if (strlen($header) < 4) {
            return [];
        }
        $b1 = ord($header[1]);
        $b2 = ord($header[2]);
        $versionBits = ($b1 >> 3) & 0x03;
        $layerBits = ($b1 >> 1) & 0x03;
        $bitrateIndex = ($b2 >> 4) & 0x0f;
        $sampleRateIndex = ($b2 >> 2) & 0x03;
        $padding = ($b2 >> 1) & 0x01;
        if ($versionBits === 1 || $layerBits === 0 || $bitrateIndex === 0 || $bitrateIndex === 15 || $sampleRateIndex === 3) {
            return [];
        }
        $versionKey = match ($versionBits) {
            3 => 'mpeg1',
            2 => 'mpeg2',
            default => 'mpeg25',
        };
        $layerKey = match ($layerBits) {
            3 => 'layer1',
            2 => 'layer2',
            1 => 'layer3',
            default => '',
        };
        $bitrates = [
            'mpeg1' => [
                'layer1' => [0, 32, 64, 96, 128, 160, 192, 224, 256, 288, 320, 352, 384, 416, 448, 0],
                'layer2' => [0, 32, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320, 384, 0],
                'layer3' => [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320, 0],
            ],
            'mpeg2' => [
                'layer1' => [0, 32, 48, 56, 64, 80, 96, 112, 128, 144, 160, 176, 192, 224, 256, 0],
                'layer2' => [0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160, 0],
                'layer3' => [0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160, 0],
            ],
            'mpeg25' => [
                'layer1' => [0, 32, 48, 56, 64, 80, 96, 112, 128, 144, 160, 176, 192, 224, 256, 0],
                'layer2' => [0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160, 0],
                'layer3' => [0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160, 0],
            ],
        ];
        $sampleRates = [
            'mpeg1' => [44100, 48000, 32000],
            'mpeg2' => [22050, 24000, 16000],
            'mpeg25' => [11025, 12000, 8000],
        ];
        $bitrate = (int)($bitrates[$versionKey][$layerKey][$bitrateIndex] ?? 0);
        $sampleRate = (int)($sampleRates[$versionKey][$sampleRateIndex] ?? 0);
        if ($bitrate <= 0 || $sampleRate <= 0) {
            return [];
        }
        if ($layerKey === 'layer1') {
            $frameLength = (int)floor(((12 * $bitrate * 1000) / $sampleRate + $padding) * 4);
            $samples = 384;
        } else {
            $coefficient = $versionKey === 'mpeg1' || $layerKey === 'layer2' ? 144 : 72;
            $frameLength = (int)floor(($coefficient * $bitrate * 1000) / $sampleRate + $padding);
            $samples = $versionKey === 'mpeg1' || $layerKey === 'layer2' ? 1152 : 576;
        }
        return [
            'frame_length' => $frameLength,
            'samples' => $samples,
            'sample_rate' => $sampleRate,
            'bitrate' => $bitrate,
        ];
    }

    private static function estimateMp3ByFirstFrame(string $content, string $filePath): float
    {
        $parsed = self::parseMp3FrameHeader(substr($content, self::skipId3Tag($content), 4));
        $bitrate = (int)($parsed['bitrate'] ?? 0);
        $size = filesize($filePath);
        if (!$size || $size <= 0 || $bitrate <= 0) {
            return 0;
        }
        return round(($size * 8) / ($bitrate * 1000), 2);
    }

    private static function estimateWav(string $filePath): float
    {
        $content = @file_get_contents($filePath, false, null, 0, 44);
        if ($content === false || strlen($content) < 44 || substr($content, 0, 4) !== 'RIFF' || substr($content, 8, 4) !== 'WAVE') {
            return 0;
        }
        $byteRate = unpack('V', substr($content, 28, 4))[1] ?? 0;
        $dataSize = filesize($filePath) - 44;
        if ($byteRate <= 0 || $dataSize <= 0) {
            return 0;
        }
        return round($dataSize / $byteRate, 2);
    }
}
