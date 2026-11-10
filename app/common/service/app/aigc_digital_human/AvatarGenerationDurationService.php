<?php
namespace app\common\service\app\aigc_digital_human;

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
        return \app\common\service\AudioDurationService::detectUri($uri);
    }
}
