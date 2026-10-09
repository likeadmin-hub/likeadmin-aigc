<?php
namespace app\common\service\app\aigc_short_drama;

use InvalidArgumentException;

/** Script-shot policy only; provider video capabilities remain independent. */
final class ShortDramaShotDuration
{
    public const MIN = 4;
    public const MAX = 15;
    public const DEFAULT = 5;
    public const INSTRUCTION = '分镜时长系统约束：每个新建或重新生成的分镜为 4-15 秒，先确定本镜实际可见动作、对白、运镜及必要停顿，再按完成这些内容所需的时间分配；不得沿用空白分镜的初始时长，也不得为凑总时长拉长静止或单一动作。超过 15 秒的内容拆成连续镜头，保留完整剧情和对白。此范围优先于旧模板、历史任务及素材中冲突的分镜时长规则。不得改动本次修改范围以外的历史镜头。实际视频生成仍须遵守所选模型能力。';

    /** Tenant-configurable policy. The broad bounds prevent broken task data. */
    public static function defaultRule(): array
    {
        return ['min_seconds' => self::MIN, 'max_seconds' => self::MAX, 'default_seconds' => self::DEFAULT];
    }

    /** The blank-editor seed is never a creative instruction for the model. */
    public static function modelRule(array $rule = []): array
    {
        $policy = self::normalizeRule($rule);
        return ['min_seconds' => $policy['min_seconds'], 'max_seconds' => $policy['max_seconds']];
    }

    public static function normalizeRule(array $rule): array
    {
        $min = max(1, min(60, (int)($rule['min_seconds'] ?? self::MIN)));
        $max = max($min, min(60, (int)($rule['max_seconds'] ?? self::MAX)));
        $default = max($min, min($max, (int)($rule['default_seconds'] ?? self::DEFAULT)));
        return ['min_seconds' => !empty($rule['timeline_override']) ? 0.001 : $min, 'max_seconds' => $max, 'default_seconds' => $default]
            + (!empty($rule['timeline_override']) ? ['timeline_override' => true] : []);
    }

    public static function rule(array $request = []): array
    {
        $candidate = $request['shot_duration_rule'] ?? ($request['generation_settings']['shot_duration_rule'] ?? []);
        $rule = self::normalizeRule(is_array($candidate) ? $candidate : []);
        $timing = ShortDramaEpisodeDuration::policy($request);
        if (ShortDramaEpisodeDuration::active($request) && (($timing['source'] ?? '') === 'timeline'
            || (($timing['source'] ?? '') === 'user' && $timing['target_seconds'] < $rule['min_seconds']))) {
            $rule['timeline_override'] = true;
            $rule['min_seconds'] = 0.001;
        }
        return $rule;
    }

    public static function normalize(mixed $value, array $rule = []): float
    {
        $policy = self::normalizeRule($rule);
        $seconds = is_numeric($value) ? (float)$value : $policy['default_seconds'];
        if (!is_finite($seconds) || $seconds <= 0) $seconds = $policy['default_seconds'];
        return max($policy['min_seconds'], min($policy['max_seconds'], $seconds));
    }

    public static function contains(mixed $value, array $rule = []): bool
    {
        $policy = self::normalizeRule($rule);
        return is_numeric($value) && is_finite((float)$value) && $value >= $policy['min_seconds'] && $value <= $policy['max_seconds'];
    }

    public static function instruction(array $rule = []): string
    {
        $policy = self::normalizeRule($rule);
        return '分镜时长系统约束：每个新建或重新生成的分镜为 ' . $policy['min_seconds'] . '-' . $policy['max_seconds']
            . ' 秒，先确定本镜实际可见动作、对白、运镜及必要停顿，再按完成这些内容所需的时间分配；不得沿用空白分镜的初始时长，也不得为凑总时长拉长静止或单一动作。超过 ' . $policy['max_seconds']
            . ' 秒的内容拆成连续镜头，保留完整剧情和对白。此范围优先于旧模板、历史任务及素材中冲突的分镜时长规则。不得改动本次修改范围以外的历史镜头。实际视频生成仍须遵守所选模型能力。';
    }

    /** Apply only to configured instructions, before interpolating user content. */
    public static function upgradeInstructions(string $text, array $rule = []): string
    {
        $policy = self::normalizeRule($rule);
        $range = $policy['min_seconds'] . '-' . $policy['max_seconds'];
        $text = preg_replace('/(?<![\d.])2\s*[-–—~～至到]\s*5\s*(seconds?|秒)/iu', $range . ' $1', $text);
        $text = str_replace('recommended_duration_seconds must be 2, 3, 4, or 5 only.', 'recommended_duration_seconds must be between ' . $policy['min_seconds'] . ' and ' . $policy['max_seconds'] . ' seconds.', $text);
        $text = str_replace(['Normal action shots: 2-3 seconds.', 'Emotional close-ups: 3-4 seconds.', 'Establishing shots: 3-5 seconds.', 'Climax/reveal shots: 4-5 seconds.'], 'Choose duration within ' . $range . ' seconds to fit the action, dialogue and emotion.', $text);
        return str_replace(['split a segment only when it is longer than 5 seconds', 'normally around 12-24 shots for the one-minute default'], ['split a segment only when it is longer than ' . $policy['max_seconds'] . ' seconds', 'with shot count determined by the story and requested duration'], $text);
    }

    /** Preserve exact totals without an undersized remainder (16s becomes 8+8). */
    public static function split(int $seconds, array $rule = []): array
    {
        $policy = self::normalizeRule($rule);
        if ($seconds < $policy['min_seconds']) throw new InvalidArgumentException('单个时间码片段不能少于 ' . $policy['min_seconds'] . ' 秒，请合并相邻片段或调整时间码后重试');
        $count = (int)ceil($seconds / $policy['max_seconds']);
        $base = intdiv($seconds, $count);
        $remainder = $seconds % $count;
        return array_map(static fn(int $index): int => $base + ($index < $remainder ? 1 : 0), range(0, $count - 1));
    }
}
