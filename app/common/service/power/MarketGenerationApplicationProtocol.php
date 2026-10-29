<?php
namespace app\common\service\power;

use Exception;

/** Registered application codecs. Transport, media uploads, quotes and wallets stay with the runtime. */
final class MarketGenerationApplicationProtocol
{
    public static function nano(array $snapshot, array $request, string $idempotency, array $references, array $context, string $resolution): array
    {
        MarketGenerationAdapterRegistry::applicationProtocol('nano_banana', $snapshot);
        $payload = array_merge(MarketGenerationSchema::array($snapshot['locked_params'] ?? []), $context, [
            'action'=>$references === [] ? 'generate' : 'edit', 'prompt'=>(string)($request['prompt'] ?? ''),
            'image_urls'=>$references, 'idempotency_key'=>$idempotency,
        ]);
        if ($resolution !== '') $payload['resolution'] = $resolution;
        if (trim((string)($request['ratio'] ?? '')) !== '') $payload['aspect_ratio'] = trim((string)$request['ratio']);
        return array_filter($payload, static fn($v)=>$v !== '' && $v !== null && $v !== []);
    }

    public static function video(array $snapshot, array $request, string $idempotency, array $assets, array $context, string $resolution, int $duration, callable $ratioResolver): array
    {
        $app = (string)$snapshot['app_code'];
        $protocol = MarketGenerationAdapterRegistry::applicationProtocol($app, $snapshot);
        $locked = self::arrayValue($snapshot['locked_params'] ?? []);
        // Pricing variants choose the local SKU only; they are not provider parameters.
        $locked = array_filter($locked, static fn($key): bool => is_string($key)
            && !str_starts_with($key, '_') && $key !== 'pricing_variant', ARRAY_FILTER_USE_KEY);
        if ($app === 'full_video') {
            $prompt = trim((string)($request['prompt'] ?? ''));
            if ($prompt === '') {
                throw new Exception('full_video generation requires a text prompt.');
            }
            foreach (['prompt', 'content', 'aspect_ratio', 'ratio', 'size', 'quality', 'n', 'negative_prompt', 'image_urls', 'video_urls', 'audio_urls'] as $key) {
                unset($locked[$key]);
            }
            $ratio = $ratioResolver();
            if ($ratio === '' && ($assets['image'] !== [] || $assets['video'] !== [] || $assets['audio'] !== [])) {
                $ratio = 'adaptive';
            }
            return array_filter(array_merge($locked, $context, [
                'model' => trim((string)($locked['model'] ?? $snapshot['model_code'] ?? '')) ?: 'full-video',
                'content' => MarketGenerationMaterialEncoding::content($request, $prompt),
                'resolution' => strtoupper($resolution ?: '480P'),
                'duration' => $duration > 0 ? $duration : null,
                'ratio' => $ratio,
                'aigc_watermark' => $request['aigc_watermark'] ?? $request['aigcWatermark'] ?? null,
                'callback_url' => $request['callback_url'] ?? $request['callbackUrl'] ?? null,
                'idempotency_key' => $idempotency,
            ]), static fn($value) => $value !== '' && $value !== [] && $value !== null);
        }
        if ($app === 'happy_horse') {
            $method = strtolower(trim((string)($request['generation_method'] ?? $request['generationMethod'] ?? '')));
            $model = self::happyHorseModel($locked, $assets, $method);
            $media = array_merge(
                array_map(static fn(string $url): array => ['url' => $url, 'type' => 'video'], $assets['video']),
                array_map(static fn(string $url): array => ['url' => $url, 'type' => 'image'], $assets['image'])
            );
            return array_filter(array_merge($locked, $context, self::appRequestOptions($request, ['seed', 'watermark', 'audio_setting', 'callback_url']), ['model' => $model, 'prompt' => trim((string)($request['prompt'] ?? '')), 'resolution' => strtoupper($resolution), 'duration' => $duration > 0 ? $duration : null, 'ratio' => in_array($model, ['happyhorse-1.1-i2v', 'happyhorse-1.0-video-edit'], true) ? null : (string)($request['ratio'] ?? ''), 'media' => $media, 'idempotency_key' => $idempotency]), static fn($value) => $value !== '' && $value !== [] && $value !== null);
        }
        if ($app === 'seedance') {
            if (!empty($request['tools']) && array_filter($assets)) throw new Exception('联网搜索仅支持文生视频');
            $content = MarketGenerationMaterialEncoding::seedance($request);
            $hasVideo = in_array('video_url', array_column($content, 'type'), true);
            foreach (['image_urls', 'video_urls', 'audio_urls', 'content'] as $key) {
                unset($locked[$key]);
            }
            return array_filter(array_merge($locked, $context, self::appRequestOptions($request, ['seed', 'draft', 'frames', 'watermark', 'camera_fixed', 'service_tier', 'tools', 'callback_url']), ['model' => (string)($locked['model'] ?? ($hasVideo ? 'seedance-2-video-2-video' : 'seedance-2-text-2-video')), 'content' => $content, 'ratio' => (string)($request['ratio'] ?? ''), 'resolution' => $resolution, 'duration' => $duration > 0 ? $duration : null, 'generate_audio' => $request['generate_audio'] ?? null, 'idempotency_key' => $idempotency]), static fn($value) => $value !== '' && $value !== [] && $value !== null);
        }
        if ($app === 'grok_video') {
            if ($assets['video'] !== [] || $assets['audio'] !== []) throw new Exception('当前 Grok 视频接口仅支持参考图片，请移除视频或音频素材');
            $model = trim((string)($locked['model'] ?? $snapshot['model_code'] ?? 'grok-video'));
            if ($model === 'grok-imagine-video-1.5' && $assets['image'] === []) throw new Exception('Grok 标准版需要至少一张参考图片');
            if ($model === 'grok-imagine-video-1.5' && $duration > 15) throw new Exception('Grok 标准版仅支持 1–15 秒');
            return array_filter(array_merge($locked, $context, ['model' => $model, 'prompt' => trim((string)($request['prompt'] ?? '')), 'resolution' => $resolution, 'duration' => $duration > 0 ? $duration : null, 'aspect_ratio' => (string)($request['aspect_ratio'] ?? $request['ratio'] ?? ''), 'image_urls' => $assets['image'], 'idempotency_key' => $idempotency]), static fn($value) => $value !== '' && $value !== [] && $value !== null);
        }
        $model = trim((string)($locked['model'] ?? ''));
        if ($model === '') {
            $model = trim((string)($snapshot['model_code'] ?? ''));
        }
        if ($model === '') {
            $model = $assets['video'] !== [] ? 'wan2.7-videoedit' : ($assets['image'] !== [] ? 'wan2.7-r2v' : 'wan2.7');
        }
        foreach (['image_urls', 'image_with_roles', 'video_urls', 'audio_urls', 'audio_url'] as $key) {
            unset($locked[$key]);
        }
        $payload = array_merge($locked, $context, self::appRequestOptions($request, ['seed', 'watermark', 'negative_prompt', 'prompt_extend', 'metadata', 'callback_url']), [
            'model' => $model,
            'prompt' => trim((string)($request['prompt'] ?? '')),
            'resolution' => $resolution,
            'duration' => $duration > 0 ? $duration : null,
            'size' => (string)($request['ratio'] ?? ''),
            'idempotency_key' => $idempotency,
        ]);
        if ($protocol === 'wan_reference_v1' || ($protocol === 'wan_auto_v1' && $model === 'wan2.7-r2v')) {
            $payload['image_with_roles'] = MarketGenerationMaterialEncoding::wanImages($request);
        } elseif ($protocol === 'wan_edit_v1' || ($protocol === 'wan_auto_v1' && $model === 'wan2.7-videoedit')) {
            $payload['image_urls'] = $assets['image'];
            $payload['video_urls'] = $assets['video'];
        } elseif ($protocol === 'wan_text_v1' || ($protocol === 'wan_auto_v1' && $model === 'wan2.7')) {
            $payload['image_urls'] = $assets['image'];
        }
        $payload['audio_url'] = $assets['audio'][0] ?? null;
        return array_filter($payload, static fn($value) => $value !== '' && $value !== [] && $value !== null);
    }

    public static function happyHorseModel(array $locked, array $assets, string $method): string
    {
        $model = trim((string)($locked['model'] ?? ''));
        if ($model !== '') return $model;
        if ($assets['video'] !== []) return 'happyhorse-1.0-video-edit';
        if ($assets['image'] === []) return 'happyhorse-1.1-t2v';
        return count($assets['image']) === 1
            && !in_array($method, ['image_reference', 'omni_reference', 'multi_frame'], true)
            ? 'happyhorse-1.1-i2v' : 'happyhorse-1.1-r2v';
    }

    private static function appRequestOptions(array $request, array $allowed): array
    {
        return array_intersect_key($request, array_flip($allowed));
    }

    private static function arrayValue($value): array { return MarketGenerationSchema::array($value); }
}
