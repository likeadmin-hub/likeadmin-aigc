<?php
namespace app\common\service\power;

use app\common\service\ai\StudioMediaInputService;
use InvalidArgumentException;

/** Reference input limits, independent of the generated video's duration. */
final class MarketReferenceVideoDuration
{
    public static function limits(array $metadata, string $model = '', string $app = '', string $api = ''): array
    {
        $model = strtolower((string)($metadata['upstream_metadata']['model_code'] ?? $model));
        $limits = match ($model) {
            'bytedance-seedance-2', 'bytedance-seedance-2-fast', 'bytedance-seedance-2-mini',
            'doubao-seedance-2-0-260128', 'doubao-seedance-2-0-fast-260128', 'doubao-seedance-2-0-mini-260615',
            'wan-3-0-video', 'wan-3-0-video-prime', 'wan3.0-video' => ['single'=>15, 'total'=>15],
            'minimax-h3', 'h3-video' => ['minimum'=>2, 'single'=>15, 'total'=>15],
            'kling-3.0-video', 'kling-3-0-video', 'kling-3.0/video' => ['subject_clip_min'=>3, 'subject_clip_max'=>8],
            'bytedance-seedance-2-5' => ['single'=>30, 'total'=>30],
            'kling-4-0' => ['single'=>30, 'total'=>30, 'subject'=>8],
            'wan-2-6', 'wan-2-6-flash' => ['single'=>30],
            'wan-2-7' => ['single'=>30, 'first_clip'=>10],
            'kling-3-0-omni' => ['single'=>15.5],
            'gemini-omni-video', 'google-gemini-omni-flash-1-1' => ['single'=>30, 'full_clip'=>10],
            default => match ($app.':'.$api) {
                'seedance:create', 'seedance2_pro:create' => ['single'=>15, 'total'=>15],
                // The resolution routes share the MiniMax H3 input envelope.
                'full_video:submit' => ['minimum'=>2, 'single'=>15, 'total'=>15],
                'wan:create' => ['single'=>10],
                'happy_horse:submit' => ['single'=>60],
                default => [],
            },
        };
        foreach ((array)($metadata['reference_video_duration_limits'] ?? []) as $key=>$seconds) {
            if (in_array($key, ['minimum', 'single', 'total', 'full_clip', 'first_clip', 'subject', 'subject_clip_min', 'subject_clip_max'], true) && is_numeric($seconds)
                && is_finite((float)$seconds) && (float)$seconds > 0) {
                $limits[$key] = !isset($limits[$key]) ? (float)$seconds
                    : (in_array($key, ['minimum', 'subject_clip_min'], true)
                        ? max($limits[$key], (float)$seconds) : min($limits[$key], (float)$seconds));
            }
        }
        return $limits;
    }

    public static function probeForOwner(int $tenantId, int $userId): callable
    {
        return static function (string $url) use ($tenantId, $userId): float {
            if ($tenantId > 0 && $userId > 0 &&
                str_starts_with(ltrim((string)parse_url($url, PHP_URL_PATH), '/'), 'uploads/')) {
                try {
                    return (float)StudioMediaInputService::validateVideo($tenantId, $userId, $url, PHP_INT_MAX)['duration'];
                } catch (\RuntimeException) {
                    // External references under a similar path retain the safe public URL probe.
                }
            }
            return (float)MarketGenerationMedia::videoFacts($url)['duration'];
        };
    }

    public static function visualProbeForOwner(int $tenantId, int $userId): callable
    {
        return static function (string $url) use ($tenantId, $userId): array {
            if ($tenantId > 0 && $userId > 0 &&
                str_starts_with(ltrim((string)parse_url($url, PHP_URL_PATH), '/'), 'uploads/')) {
                try {
                    return ['kind'=>'video', 'duration'=>StudioMediaInputService::validateVideo(
                        $tenantId, $userId, $url, PHP_INT_MAX)['duration']];
                } catch (\RuntimeException) {
                    try {
                        StudioMediaInputService::validateImages($tenantId, $userId, [['url'=>$url]], 1);
                        return ['kind'=>'image'];
                    } catch (\RuntimeException) {
                        // Use the public probe for external URLs.
                    }
                }
            }
            return MarketGenerationMedia::visualFacts($url);
        };
    }

    private static function validateSubjectClips(array $native, array $limits, callable $visualProbe): void
    {
        $minimum = $limits['subject_clip_min'] ?? 0;
        $maximum = $limits['subject_clip_max'] ?? INF;
        foreach (($native['kling_elements'] ?? $native['input']['kling_elements'] ?? []) as $element) {
            foreach (($element['element_input_urls'] ?? []) as $url) {
                $facts = $visualProbe($url);
                if ($facts['kind'] !== 'video') continue;
                $sourceMs = (float)$facts['duration'] * 1000;
                $start = filter_var($element['start_time'] ?? 0, FILTER_VALIDATE_INT);
                $end = isset($element['end_time'])
                    ? filter_var($element['end_time'], FILTER_VALIDATE_INT) : min(8000, $sourceMs);
                if ($start === false || $end === false) {
                    throw new InvalidArgumentException('主体视频截取起止点必须使用整数毫秒。');
                }
                $clipSeconds = ($end - $start) / 1000;
                if ($start < 0 || $end > $sourceMs + 0.001 ||
                    $clipSeconds < $minimum - 0.000001 || $clipSeconds > $maximum + 0.000001) {
                    throw new InvalidArgumentException(
                        '主体视频截取片段须为'.$minimum.'至'.$maximum.'秒，当前片段为'.round($clipSeconds,6)
                        .'秒。请调整截取范围，确保结束点不超过原视频。'
                    );
                }
            }
        }
    }

    public static function validate(
        array $native,
        array $limits,
        array $declaredKinds = [],
        ?callable $probe = null,
        ?callable $visualProbe = null
    ): void
    {
        if (!$limits) return;
        if (isset($limits['subject_clip_max'])) {
            self::validateSubjectClips($native, $limits, $visualProbe ?? [MarketGenerationMedia::class, 'visualFacts']);
        }
        if (!array_intersect_key($limits, array_flip(['minimum', 'single', 'total', 'first_clip', 'subject']))) return;
        // Keep repeated uses in the total even when URLs point to the same file.
        $videos = MarketGenerationMedia::inputs($native, $visualProbe, $declaredKinds)['video'];
        $subjectUrls = isset($limits['subject']) ? MarketGenerationMediaInputs::visualUrls($native) : [];
        $firstClip = $native['first_clip_url'] ?? $native['input']['first_clip_url'] ?? null;
        if (!$firstClip) foreach ((array)($native['input']['media'] ?? []) as $item) {
            if (($item['type'] ?? '') === 'first_clip') $firstClip = $item['url'] ?? null;
        }
        $total = 0.0;
        foreach ($videos as $url) {
            // A provider-owned asset ID has no readable local bytes.
            if (str_starts_with($url, 'asset://')) continue;
            $seconds = MarketGenerationMedia::seconds(['video_url'=>$url], false, $probe);
            $maximum = $limits['single'] ?? INF;
            if ($url === $firstClip) $maximum = min($maximum, $limits['first_clip'] ?? INF);
            if (in_array($url, $subjectUrls, true)) $maximum = min($maximum, $limits['subject']);
            $minimum = $limits['minimum'] ?? 0;
            if ($seconds < $minimum - 0.000001) {
                throw new InvalidArgumentException('单个参考视频至少需要'.$minimum.'秒，这条视频为'.round($seconds,6).'秒。请更换视频。');
            }
            if ($seconds > $maximum + 0.000001) {
                throw new InvalidArgumentException('单个参考视频不能超过'.$maximum.'秒，这条视频为'.round($seconds,6).'秒。请裁剪或更换视频。');
            }
            $total += $seconds;
            if (isset($limits['total']) && $total > $limits['total'] + 0.000001) {
                throw new InvalidArgumentException('参考视频总时长不能超过'.$limits['total'].'秒，当前合计'.round($total,6).'秒。请裁剪或减少视频。');
            }
        }
    }
}
