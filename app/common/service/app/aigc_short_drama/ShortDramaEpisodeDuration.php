<?php
namespace app\common\service\app\aigc_short_drama;

use RuntimeException;

/** Immutable editorial timing policy. Does not describe provider video capabilities. */
final class ShortDramaEpisodeDuration
{
    public const VERSION = 1;

    public static function defaults(): array
    {
        return ['enabled' => false, 'target_seconds' => 120, 'min_seconds' => 110, 'max_seconds' => 130];
    }

    public static function normalize(array $rule): array
    {
        $rule = array_replace(self::defaults(), $rule);
        foreach (['target_seconds', 'min_seconds', 'max_seconds'] as $key) {
            if (!is_numeric($rule[$key]) || $rule[$key] < 1 || $rule[$key] > 3600) {
                throw new RuntimeException('每集时长必须在1至3600秒之间', 422);
            }
            $rule[$key] = (int)$rule[$key];
        }
        if ($rule['min_seconds'] > $rule['target_seconds'] || $rule['target_seconds'] > $rule['max_seconds']) {
            throw new RuntimeException('每集时长须满足：下限 ≤ 目标 ≤ 上限', 422);
        }
        return array_intersect_key(array_replace($rule, ['enabled' => (bool)$rule['enabled']]), self::defaults());
    }

    public static function policy(array $request): array
    {
        return (array)($request['episode_duration_policy'] ?? $request['generation_settings']['episode_duration_policy'] ?? []);
    }

    public static function active(array $request): bool
    {
        return (int)(self::policy($request)['version'] ?? 0) === self::VERSION;
    }

    public static function localRevision(array $request): bool
    {
        return (!empty($request['revision_message']) || !empty($request['revision_target']))
            && ($request['revision_policy']['mode'] ?? 'local_only') !== 'full_replan';
    }

    public static function snapshot(array $rule, float $selected, float $textDuration, array $timeline, bool $seriesTotal = false): array
    {
        $rule = self::normalize($rule);
        $timelineDuration = 0.0;
        $lastEnd = null;
        foreach ($timeline as $segment) {
            $duration = (float)($segment['duration_seconds'] ?? 0);
            $start = (float)($segment['start_seconds'] ?? 0);
            $end = (float)($segment['end_seconds'] ?? ($start + $duration));
            if (!is_finite($duration) || !is_finite($start) || !is_finite($end) || $duration <= 0 || $start < 0
                || abs($end - $start - $duration) > 0.001 || ($lastEnd !== null && abs($start - $lastEnd) > 0.001)) {
                throw new RuntimeException('时间码存在重叠、空档或无效时长，请调整后提交', 422);
            }
            $lastEnd = $end;
            $timelineDuration += $duration;
        }
        $explicit = array_values(array_filter([$timelineDuration, $selected, $textDuration], static fn($v) => $v > 0));
        if ($explicit && max($explicit) - min($explicit) > 0.001) {
            throw new RuntimeException('时间码、输入时长与所选时长不一致，请统一后提交', 422);
        }
        $target = $explicit[0] ?? $rule['target_seconds'];
        return [
            'version' => self::VERSION,
            'source' => $timeline ? 'timeline' : ($explicit ? 'user' : 'default'),
            'scope' => $seriesTotal ? 'series' : 'episode',
            'target_seconds' => $target,
            'min_seconds' => $explicit ? $target : $rule['min_seconds'],
            'max_seconds' => $explicit ? $target : $rule['max_seconds'],
            'timeline_segments' => $timeline,
        ];
    }

    /** Provider coercion must not silently rewrite an editorial duration. */
    public static function assertRenderableDuration(float $planned, float $effective): void
    {
        if ($planned <= 0 || !is_finite($planned) || abs($planned - $effective) > 0.001) {
            throw new RuntimeException('当前视频模型不支持分镜要求的' . $planned . '秒（当前规格为' . $effective . '秒），请更换支持该时长的模型，或明确调整视频生成时长；剧本时长保持不变', 422);
        }
    }

    public static function instruction(array $request): string
    {
        $policy = self::policy($request);
        if (!self::active($request)) return '';
        return '本次采用时间预算策略。' . (($policy['scope'] ?? '') === 'series' ? '整部总时长' : '每集时长')
            . '目标' . $policy['target_seconds'] . '秒，允许' . $policy['min_seconds'] . '-' . $policy['max_seconds'] . '秒。'
            . '具体时间码和对应剧情必须完整保留。剧情类型只提供节奏参考，忽略旧模板的分镜数量上下限、按场景最少几个镜头及固定单集默认时长。'
            . '按实际对白、动作和情绪安排时长与镜头数，不追加镜头凑时长，不通过仅修改秒数压缩对白。默认时长不足时正常返回，默认下限仅为参考，不补长、不重试；明确用户时长仍严格遵守。'
            . '骨架先分配各场时间预算，再展开分镜。明确时间码可短于通常片段下限；长片段允许连续拆分但不改变原时间边界。'
            . (self::localRevision($request) ? '当前为局部修改，保留未选中内容及其时长，不执行整集时间再平衡。' : '')
            . "\n时间策略=" . json_encode($policy, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** Validate semantic output before normalization can clamp or pad it. */
    public static function assertPlan(array $plan, array $request): void
    {
        if (!self::active($request) || self::localRevision($request)) return;
        $policy = self::policy($request);
        $shots = (array)($plan['storyboard'] ?? []);
        $total = 0.0;
        $boundaries = [0.0];
        foreach ($shots as $shot) {
            $duration = (float)($shot['recommended_duration_seconds'] ?? 0);
            if ($duration <= 0 || !is_finite($duration)) throw new RuntimeException('分镜缺少有效时长', 422);
            $total += $duration;
            $boundaries[] = $total;
        }
        if (!$shots || (($policy['source'] ?? '') !== 'default' && $total < $policy['min_seconds'] - 0.001) || $total > $policy['max_seconds'] + 0.001) {
            throw new RuntimeException('分镜总时长' . round($total, 3) . '秒不符合本集' . $policy['min_seconds'] . '-' . $policy['max_seconds'] . '秒要求', 422);
        }
        $elapsed = 0.0;
        foreach ((array)($policy['timeline_segments'] ?? []) as $segment) {
            $elapsed += (float)$segment['duration_seconds'];
            if (!array_filter($boundaries, static fn($n) => abs($n - $elapsed) < 0.001)) {
                throw new RuntimeException('分镜未保留用户时间码边界', 422);
            }
        }
    }
}
