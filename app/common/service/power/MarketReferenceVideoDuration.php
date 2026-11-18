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
            'minimax-h3', 'h3-video', 'wan-3-0-video', 'wan-3-0-video-prime', 'wan3.0-video' => ['single'=>15, 'total'=>15],
            'bytedance-seedance-2-5' => ['single'=>30, 'total'=>30],
            'kling-4-0' => ['single'=>30, 'total'=>30, 'subject'=>8],
            'wan-2-6', 'wan-2-6-flash' => ['single'=>30],
            'wan-2-7' => ['single'=>30, 'first_clip'=>10],
            'kling-3-0-omni' => ['single'=>15.5],
            'gemini-omni-video', 'google-gemini-omni-flash-1-1' => ['single'=>30, 'full_clip'=>10],
            default => match ($app.':'.$api) {
                'seedance:create', 'seedance2_pro:create' => ['single'=>15, 'total'=>15],
                'wan:create' => ['single'=>10],
                'happy_horse:submit' => ['single'=>60],
                default => [],
            },
        };
        foreach ((array)($metadata['reference_video_duration_limits'] ?? []) as $key=>$seconds) {
            if (in_array($key, ['single', 'total', 'full_clip', 'first_clip', 'subject'], true) && is_numeric($seconds)
                && is_finite((float)$seconds) && (float)$seconds > 0) {
                $limits[$key] = isset($limits[$key]) ? min($limits[$key], (float)$seconds) : (float)$seconds;
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

    public static function validate(array $native, array $limits, array $declaredKinds = [], ?callable $probe = null): void
    {
        if (!$limits) return;
        // Structured subject slots may contain either images or video. Reuse
        // verified media facts and preserve repeated, explicitly submitted uses.
        $videos = MarketGenerationMedia::inputs($native, null, $declaredKinds)['video'];
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
            if ($seconds > $maximum + 0.000001) {
                throw new InvalidArgumentException('单个参考视频不能超过'.$maximum.'秒，请裁剪或更换视频');
            }
            $total += $seconds;
            if (isset($limits['total']) && $total > $limits['total'] + 0.000001) {
                throw new InvalidArgumentException('参考视频总时长不能超过'.$limits['total'].'秒，请裁剪或减少视频');
            }
        }
    }
}
