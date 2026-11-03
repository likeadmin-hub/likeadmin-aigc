<?php
namespace app\common\service\power;

use Exception;

/** Registered application codecs. Transport, media uploads, quotes and wallets stay with the runtime. */
final class MarketGenerationApplicationProtocol
{
    private const SEEDANCE_MODELS = [
        'seedance-2-text-2-video', 'seedance-2-video-2-video',
        'seedance-2-fast-text-2-video', 'seedance-2-fast-video-2-video',
    ];

    /** Called only while constructing a new trusted price snapshot, never while reading/retrying one. */
    public static function bindNewSnapshot(array $snapshot): array
    {
        if (array_key_exists('application_contract', $snapshot)) {
            self::snapshotContractProfile($snapshot);
            return $snapshot;
        }
        $profile = self::reviewedApplicationProfile($snapshot);
        if ($profile !== null) $snapshot['application_contract'] = ['version'=>1, 'profile'=>$profile,
            'binding_digest'=>self::applicationBindingDigest($snapshot, $profile)];
        return $snapshot;
    }

    public static function assertSnapshotContract(array $snapshot): void
    {
        self::snapshotContractProfile($snapshot);
    }

    private static function snapshotContractProfile(array $snapshot): ?string
    {
        // Absence is an immutable legacy contract, including an old snapshot with today's exact schema fingerprint.
        if (!array_key_exists('application_contract', $snapshot)) return null;
        $contract = $snapshot['application_contract'];
        $profile = self::reviewedApplicationProfile($snapshot);
        if (!is_array($contract) || count($contract) !== 3 || array_diff(array_keys($contract), ['version','profile','binding_digest']) !== []
            || ($contract['version'] ?? null) !== 1 || $profile === null || ($contract['profile'] ?? null) !== $profile
            || !is_string($contract['binding_digest'] ?? null)
            || !hash_equals(self::applicationBindingDigest($snapshot, $profile), $contract['binding_digest'])) {
            throw new Exception('应用冻结合同的版本、身份或声明绑定不一致');
        }
        return $profile;
    }

    private static function applicationBindingDigest(array $snapshot, string $profile): string
    {
        $metadata = self::arrayValue($snapshot['market_metadata'] ?? []);
        return MarketGenerationReviewedContract::digest(['profile'=>$profile, 'resource_type'=>$snapshot['resource_type'] ?? '',
            'app_code'=>$snapshot['app_code'] ?? '', 'api_code'=>$snapshot['api_code'] ?? '',
            'sku_id'=>$snapshot['sku_id'] ?? null, 'locked_params'=>$snapshot['locked_params'] ?? [],
            'params_schema'=>$metadata['params_schema'] ?? [], 'default_params'=>$metadata['default_params'] ?? [],
            'upstream_app_metadata'=>$metadata['upstream_app_metadata'] ?? [], 'upstream_api_metadata'=>$metadata['upstream_api_metadata'] ?? []]);
    }

    private static function reviewedApplicationProfile(array $snapshot): ?string
    {
        if (self::hasBoundHappyHorseDashScopeRoute($snapshot)) return 'happy_horse_submit_dashscope_20261101';
        if (self::hasCapturedFullVideoDeclaration($snapshot)) return 'full_video_submit_20261101';
        if (($snapshot['resource_type'] ?? '') !== 'app_api' || ($snapshot['app_code'] ?? '') !== 'seedance'
            || ($snapshot['api_code'] ?? '') !== 'create') return null;
        $metadata = self::arrayValue($snapshot['market_metadata'] ?? []);
        if (($metadata['upstream_app_metadata']['id'] ?? null) !== 11 || ($metadata['upstream_app_metadata']['code'] ?? null) !== 'seedance'
            || ($metadata['upstream_api_metadata']['id'] ?? null) !== 27 || ($metadata['upstream_api_metadata']['code'] ?? null) !== 'create'
            || ($metadata['call_type'] ?? null) !== 2
            || MarketGenerationReviewedContract::digest(['value'=>$metadata['params_schema'] ?? []]) !== '9168759ee8e0400a8fd5549c8e80e6c6e01ff42b6294cb0e9065f5c11b572905'
            || MarketGenerationReviewedContract::digest(self::arrayValue($metadata['default_params'] ?? []))
                !== MarketGenerationReviewedContract::digest(['ratio'=>'adaptive','duration'=>'5','watermark'=>'','resolution'=>'720p','generate_audio'=>'1'])) return null;
        return 'seedance_create_20261101';
    }

    /** b5887e1c driver default plus the 2026-11-01 exact saved/public submit declaration. */
    private static function hasBoundHappyHorseDashScopeRoute(array $snapshot): bool
    {
        if (($snapshot['resource_type'] ?? '') !== 'app_api' || ($snapshot['app_code'] ?? '') !== 'happy_horse'
            || ($snapshot['api_code'] ?? '') !== 'submit') return false;
        $metadata = self::arrayValue($snapshot['market_metadata'] ?? []);
        return ($metadata['upstream_app_metadata']['id'] ?? null) === 12
            && ($metadata['upstream_app_metadata']['code'] ?? null) === 'happy_horse'
            && ($metadata['upstream_api_metadata']['id'] ?? null) === 34
            && ($metadata['upstream_api_metadata']['code'] ?? null) === 'submit'
            && ($metadata['call_type'] ?? null) === 2
            && MarketGenerationReviewedContract::digest(['value'=>$metadata['params_schema'] ?? []]) === '2ca6f7110869e67b6425ab6a99ac874503c12708fd912d891d6f754ea6a9b20e'
            && MarketGenerationReviewedContract::digest(self::arrayValue($metadata['default_params'] ?? []))
                === MarketGenerationReviewedContract::digest(['duration'=>5,'resolution'=>'720P']);
    }

    private static function happyHorseChannel(array $snapshot, array $locked)
    {
        if (self::snapshotContractProfile($snapshot) !== 'happy_horse_submit_dashscope_20261101') return $locked['channel'] ?? null;
        if (array_key_exists('channel', $locked) && !in_array($locked['channel'], ['', 'dashscope'], true)) {
            throw new Exception('Happy Horse 渠道与冻结的 submit 声明不一致');
        }
        return 'dashscope';
    }

    private static function hasCapturedFullVideoDeclaration(array $snapshot): bool
    {
        if (($snapshot['resource_type'] ?? '') !== 'app_api' || ($snapshot['app_code'] ?? '') !== 'full_video'
            || ($snapshot['api_code'] ?? '') !== 'submit') return false;
        $metadata = self::arrayValue($snapshot['market_metadata'] ?? []);
        return ($metadata['upstream_app_metadata']['id'] ?? null) === 24
            && ($metadata['upstream_app_metadata']['code'] ?? null) === 'full_video'
            && ($metadata['upstream_api_metadata']['id'] ?? null) === 73
            && ($metadata['upstream_api_metadata']['code'] ?? null) === 'submit'
            && ($metadata['call_type'] ?? null) === 2
            && MarketGenerationReviewedContract::digest(['value'=>$metadata['params_schema'] ?? []]) === 'd342216664eb8b43c85706fb5c712a493afdbb2c55366278b64d6464161bd188'
            && MarketGenerationReviewedContract::digest(self::arrayValue($metadata['default_params'] ?? []))
                === MarketGenerationReviewedContract::digest(['model'=>'full-video','ratio'=>'16:9','duration'=>4,'resolution'=>'480P','aigc_watermark'=>false]);
    }

    /** Only this registered application exposes its declared model as an editable business choice. */
    public static function seedanceModelControl(array $option): ?array
    {
        if (($option['resource_type'] ?? '') !== 'app_api'
            || ($option['app_code'] ?? $option['upstream_app_code'] ?? '') !== 'seedance'
            || ($option['api_code'] ?? $option['upstream_api_code'] ?? '') !== 'create') return null;
        foreach (['app_code'=>'seedance','upstream_app_code'=>'seedance','api_code'=>'create','upstream_api_code'=>'create'] as $key=>$expected) {
            if (array_key_exists($key, $option) && $option[$key] !== $expected) return null;
        }
        $metadata = self::arrayValue($option['market_metadata'] ?? $option);
        $definition = MarketGenerationSchema::properties(self::arrayValue($metadata['params_schema'] ?? []))['model'] ?? [];
        if (($definition['type'] ?? '') !== 'string') return null;
        $choices = array_values(array_intersect(MarketGenerationSchema::choices($definition), self::SEEDANCE_MODELS));
        $locked = self::arrayValue($option['locked_params'] ?? []);
        $variant = $locked['_pricing_variant'] ?? $locked['pricing_variant'] ?? null;
        if (in_array($variant, ['with_video', 'without_video'], true)) {
            $choices = array_values(array_filter($choices, static fn(string $model): bool =>
                str_ends_with($model, 'video-2-video') === ($variant === 'with_video')));
        }
        if (array_key_exists('model', $locked)) {
            if (!is_string($locked['model']) || !in_array($locked['model'], $choices, true)) return null;
            $choices = [$locked['model']];
        }
        if ($choices === []) return null;
        $field = ['key'=>'model', 'label'=>'生成型号', 'type'=>'string', 'options'=>$choices,
            'schema'=>['type'=>'string','enum'=>$choices], 'description'=>(string)($definition['description'] ?? ''), 'required'=>false];
        if (isset($locked['model'])) $field += ['locked'=>true, 'default'=>$locked['model']];
        return $field;
    }

    private static function seedanceModel(array $snapshot, array $request, bool $hasVideo): string
    {
        $locked = self::arrayValue($snapshot['locked_params'] ?? []);
        if (self::snapshotContractProfile($snapshot) !== 'seedance_create_20261101') {
            return (string)($locked['model'] ?? ($hasVideo ? 'seedance-2-video-2-video' : 'seedance-2-text-2-video'));
        }
        $provider = self::arrayValue($request['provider_params'] ?? []);
        $variant = $locked['_pricing_variant'] ?? $locked['pricing_variant'] ?? null;
        if (in_array($variant, ['with_video', 'without_video'], true) && $hasVideo !== ($variant === 'with_video')) {
            throw new Exception('Seedance 视频输入与计费规格不匹配');
        }
        if (array_key_exists('model', $provider)) {
            $control = self::seedanceModelControl($snapshot);
            if ($control === null || !is_string($provider['model']) || !in_array($provider['model'], $control['options'], true)) {
                throw new Exception('当前 Seedance API 或规格未声明所选生成型号');
            }
        }
        $model = $locked['model'] ?? $provider['model'] ?? ($hasVideo ? 'seedance-2-video-2-video' : 'seedance-2-text-2-video');
        if (!is_string($model) || !in_array($model, self::SEEDANCE_MODELS, true)
            || str_ends_with($model, 'video-2-video') !== $hasVideo) {
            throw new Exception('Seedance 生成型号与视频输入不匹配');
        }
        return $model;
    }

    /** Source driver constraints apply to the final encoded request, before reservation or asset uploads. */
    public static function assertPreparedVideo(array $snapshot, array $payload): void
    {
        $profile = self::snapshotContractProfile($snapshot);
        if ($profile === null) return;
        if ($profile === 'happy_horse_submit_dashscope_20261101') {
            if (($payload['channel'] ?? null) !== 'dashscope') throw new Exception('Happy Horse 渠道与冻结的 submit 声明不一致');
            $images = array_filter((array)($payload['media'] ?? []), static fn($item): bool => is_array($item) && ($item['type'] ?? '') === 'image');
            if (($payload['model'] ?? '') === 'happyhorse-1.1-r2v' && count($images) < 2) {
                throw new Exception('Happy Horse DashScope 多参考图生成至少需要两张图片');
            }
        }
        if ($profile !== 'full_video_submit_20261101') return;
        $references = array_filter((array)($payload['content'] ?? []), static fn($item): bool => is_array($item)
            && ($item['type'] ?? '') === 'image_url' && ($item['role'] ?? '') === 'reference_image');
        // Admission cap from the 2026-11-01 normal admin read, not a generic price/default.
        // No dynamic surcharge contract is activated by this source-declaration binding.
        if (count($references) > 5) throw new Exception('full_video 超额参考图片附加计费尚待核验，请减少参考图片');
        $resolution = strtoupper((string)($payload['resolution'] ?? '480P'));
        $remote = in_array($resolution, ['768P', '1080P', '2K'], true);
        if ($remote && ($payload['aigc_watermark'] ?? false) !== false) {
            throw new Exception('所选 full_video 分辨率不支持生成标识');
        }
        $length = 0;
        foreach ((array)($payload['content'] ?? []) as $item) {
            if (is_array($item) && ($item['type'] ?? '') === 'text') $length += mb_strlen(trim((string)($item['text'] ?? '')), 'UTF-8');
        }
        if ($length > ($remote ? 5000 : 7000)) throw new Exception('所选 full_video 分辨率的文本超过长度限制');
    }

    public static function nano(array $snapshot, array $request, string $idempotency, array $references, array $context, string $resolution): array
    {
        MarketGenerationAdapterRegistry::applicationProtocol('nano_banana', $snapshot);
        $locked = array_filter(MarketGenerationSchema::array($snapshot['locked_params'] ?? []),
            static fn($key)=>is_string($key) && !str_starts_with($key,'_') && $key !== 'pricing_variant', ARRAY_FILTER_USE_KEY);
        $payload = array_merge($locked, $context, [
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
            return array_filter(array_merge($locked, $context, self::appRequestOptions($request, ['seed', 'watermark', 'audio_setting', 'callback_url']), ['model' => $model, 'channel'=>self::happyHorseChannel($snapshot, $locked), 'prompt' => trim((string)($request['prompt'] ?? '')), 'resolution' => strtoupper($resolution), 'duration' => $duration > 0 ? $duration : null, 'ratio' => in_array($model, ['happyhorse-1.1-i2v', 'happyhorse-1.0-video-edit'], true) ? null : (string)($request['ratio'] ?? ''), 'media' => $media, 'idempotency_key' => $idempotency]), static fn($value) => $value !== '' && $value !== [] && $value !== null);
        }
        if ($app === 'seedance') {
            if (!empty($request['tools']) && array_filter($assets)) throw new Exception('联网搜索仅支持文生视频');
            $content = MarketGenerationMaterialEncoding::seedance($request);
            $hasVideo = in_array('video_url', array_column($content, 'type'), true);
            foreach (['image_urls', 'video_urls', 'audio_urls', 'content'] as $key) {
                unset($locked[$key]);
            }
            return array_filter(array_merge($locked, $context, self::appRequestOptions($request, ['seed', 'draft', 'frames', 'watermark', 'camera_fixed', 'service_tier', 'tools', 'callback_url']), ['model' => self::seedanceModel($snapshot, $request, $hasVideo), 'content' => $content, 'ratio' => (string)($request['ratio'] ?? ''), 'resolution' => $resolution, 'duration' => $duration > 0 ? $duration : null, 'generate_audio' => $request['generate_audio'] ?? null, 'idempotency_key' => $idempotency]), static fn($value) => $value !== '' && $value !== [] && $value !== null);
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
