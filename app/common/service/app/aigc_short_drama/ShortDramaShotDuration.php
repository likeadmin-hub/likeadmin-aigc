<?php
namespace app\common\service\app\aigc_short_drama;

use InvalidArgumentException;

/** Script-shot policy only; provider video capabilities remain independent. */
final class ShortDramaShotDuration
{
    public const MIN = 4;
    public const MAX = 15;
    public const DEFAULT = 5;
    public const INSTRUCTION = '分镜时长系统约束：每个新建或重新生成的分镜为 4-15 秒，按动作、对白和情绪需要分配，不固定为 5 秒；超过 15 秒的内容拆成连续镜头，保留完整剧情和对白。此范围优先于旧模板、历史任务及素材中冲突的分镜时长规则。不得改动本次修改范围以外的历史镜头。实际视频生成仍须遵守所选模型能力。';

    public static function normalize(mixed $value): float
    {
        $seconds = is_numeric($value) ? (float)$value : self::DEFAULT;
        if (!is_finite($seconds) || $seconds <= 0) $seconds = self::DEFAULT;
        return max(self::MIN, min(self::MAX, $seconds));
    }

    public static function contains(mixed $value): bool
    {
        return is_numeric($value) && is_finite((float)$value) && $value >= self::MIN && $value <= self::MAX;
    }

    /** Apply only to configured instructions, before interpolating user content. */
    public static function upgradeInstructions(string $text): string
    {
        $text = preg_replace('/(?<![\d.])2\s*[-–—~～至到]\s*5\s*(seconds?|秒)/iu', '4-15 $1', $text);
        $text = str_replace('recommended_duration_seconds must be 2, 3, 4, or 5 only.', 'recommended_duration_seconds must be between 4 and 15 seconds.', $text);
        $text = str_replace(['Normal action shots: 2-3 seconds.', 'Emotional close-ups: 3-4 seconds.', 'Establishing shots: 3-5 seconds.', 'Climax/reveal shots: 4-5 seconds.'], 'Choose duration within 4-15 seconds to fit the action, dialogue and emotion.', $text);
        return str_replace(['split a segment only when it is longer than 5 seconds', 'normally around 12-24 shots for the one-minute default'], ['split a segment only when it is longer than 15 seconds', 'with shot count determined by the story and requested duration'], $text);
    }

    /** Preserve exact totals without an undersized remainder (16s becomes 8+8). */
    public static function split(int $seconds): array
    {
        if ($seconds < self::MIN) throw new InvalidArgumentException('单个时间码片段不能少于 4 秒，请合并相邻片段或调整时间码后重试');
        $count = (int)ceil($seconds / self::MAX);
        $base = intdiv($seconds, $count);
        $remainder = $seconds % $count;
        return array_map(static fn(int $index): int => $base + ($index < $remainder ? 1 : 0), range(0, $count - 1));
    }
}
