<?php
declare(strict_types=1);

namespace app\common\service\decorate\agent;

use RuntimeException;

/** Ephemeral visual-review transport copies. Never stores assets or changes their identity. */
final class DecorationAgentReviewImages
{
    public const WIDTH = 375;
    private const MAX_BYTES = 10485760;
    private const MAX_PIXELS = 10000000;
    private const MAX_DIMENSION = 10000;

    public static function dimensions(int $width, int $height): array
    {
        return [
            'source' => ['width_px' => $width > 0 ? $width : null, 'height_px' => $height > 0 ? $height : null],
            'model_input' => $width > 0 && $height > 0 ? [
                'width_px' => self::WIDTH,
                'height_px' => max(1, (int)round($height * self::WIDTH / $width)),
                'to_logical_px_scale' => 1,
            ] : null,
        ];
    }

    /** Call only after the existing ownership and immutable-byte checks. */
    public static function references(array $references, bool $visualReview): array
    {
        if (!$visualReview) return $references;
        if (count($references) !== 2) throw new RuntimeException('视觉复审必须提供实际截图和批准设计两张图片');
        foreach (['imagecreatefromstring', 'imagecreatetruecolor', 'imagecopyresampled', 'imagepng'] as $function) {
            if (!function_exists($function)) throw new RuntimeException('视觉复审等宽图片处理不可用，请检查GD');
        }
        $result = [];
        foreach ($references as $reference) $result[] = self::normalize($reference);
        return $result;
    }

    private static function normalize(string $reference): string
    {
        $comma = strpos($reference, ',');
        if ($comma === false || !in_array(substr($reference, 0, $comma), ['data:image/png;base64', 'data:image/jpeg;base64', 'data:image/webp;base64'], true)) {
            throw new RuntimeException('视觉复审图片必须是已验证的内存图片');
        }
        $encodedLength = strlen($reference) - $comma - 1;
        if ($encodedLength > 4 * (int)ceil(self::MAX_BYTES / 3)) throw new RuntimeException('视觉复审图片不能超过10MB');
        self::assertDecodeMemory($encodedLength, self::availableMemory());
        $bytes = base64_decode(substr($reference, $comma + 1), true);
        if ($bytes === false || strlen($bytes) > self::MAX_BYTES) throw new RuntimeException('视觉复审图片编码无效或超过10MB');
        $info = @getimagesizefromstring($bytes);
        if (!$info || !in_array($info['mime'] ?? '', ['image/png', 'image/jpeg', 'image/webp'], true) || substr($reference, 0, $comma) !== 'data:' . $info['mime'] . ';base64') throw new RuntimeException('视觉复审图片内容无效');
        [$width, $height] = $info;
        if ($width < 1 || $height < 1) throw new RuntimeException('视觉复审图片尺寸无效');
        $targetHeight = self::dimensions($width, $height)['model_input']['height_px'];
        if ($width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION || $width * $height > self::MAX_PIXELS || $targetHeight > self::MAX_DIMENSION || self::WIDTH * $targetHeight > self::MAX_PIXELS) {
            throw new RuntimeException('视觉复审图片像素尺寸过大');
        }
        self::assertMemory($width, $height, $targetHeight, strlen($bytes), self::availableMemory());
        $source = @imagecreatefromstring($bytes);
        if ($source === false) throw new RuntimeException('视觉复审图片解码失败');
        $target = null;
        try {
            // Decode-check even an unchanged image; preserve its original bytes on the wire.
            if ($width === self::WIDTH) return $reference;
            $target = imagecreatetruecolor(self::WIDTH, $targetHeight);
            if ($target === false) throw new RuntimeException('视觉复审图片副本创建失败');
            imagealphablending($target, false);
            imagesavealpha($target, true);
            imagefill($target, 0, 0, imagecolorallocatealpha($target, 0, 0, 0, 127));
            if (!imagecopyresampled($target, $source, 0, 0, 0, 0, self::WIDTH, $targetHeight, $width, $height)) throw new RuntimeException('视觉复审图片缩放失败');
            ob_start();
            try {
                if (!imagepng($target)) throw new RuntimeException('视觉复审图片副本编码失败');
                $output = ob_get_contents();
            } finally {
                ob_end_clean();
            }
            if (strlen($output) > self::MAX_BYTES) throw new RuntimeException('视觉复审图片副本超过10MB');
            return 'data:image/png;base64,' . base64_encode($output);
        } finally {
            imagedestroy($source);
            if ($target !== null && $target !== false) imagedestroy($target);
        }
    }

    /** Account for the temporary encoded substring and decoded bytes before allocating either. */
    private static function assertDecodeMemory(int $encodedLength, int $available): void
    {
        if (16777216 + $encodedLength + 3 * (int)ceil($encodedLength / 4) > $available) {
            throw new RuntimeException('视觉复审图片解码内存不足，尚未调用模型');
        }
    }

    /** Conservative decoder/encoder/base64 headroom, checked before any GD allocation. */
    private static function assertMemory(int $width, int $height, int $targetHeight, int $bytes, int $available): void
    {
        $required = 16777216 + $width * $height * 16 + self::WIDTH * $targetHeight * 8 + $bytes * 6;
        if ($required > $available) throw new RuntimeException('视觉复审图片处理内存不足，尚未调用模型');
    }

    private static function availableMemory(): int
    {
        $value = trim((string)ini_get('memory_limit'));
        if ($value === '-1') return PHP_INT_MAX;
        $limit = (int)$value;
        $unit = strtolower(substr($value, -1));
        if ($unit === 'g') $limit *= 1073741824;
        elseif ($unit === 'm') $limit *= 1048576;
        elseif ($unit === 'k') $limit *= 1024;
        return max(0, $limit - memory_get_usage(true));
    }
}
