<?php

namespace app\common\service\power;

use InvalidArgumentException;

/** Trusted shared runtimes own payloads, supplier transport and result lifecycles. */
final class MarketGenerationAdapterRegistry
{
    private const MODELS = [
        'image' => MarketImageModelRuntimeService::class,
        'video' => MarketVideoModelRuntimeService::class,
        'text' => MarketTextModelRuntimeService::class,
    ];
    private const VIDEO_APPS = [
        'seedance' => ['query' => 'GET', 'assets' => true],
        'wan' => ['query' => 'GET'],
        'grok_video' => ['query' => 'GET'],
        'happy_horse' => ['query' => 'POST'],
        'full_video' => ['query' => 'POST'],
    ];
    private const APPLICATIONS = [
        'nano_banana' => MarketNanoBananaAppRuntimeService::class,
        MarketLipsyncAppRuntimeService::UPSTREAM_APP_CODE => MarketLipsyncAppRuntimeService::class,
        MarketPicLipsyncAppRuntimeService::UPSTREAM_APP_CODE => MarketPicLipsyncAppRuntimeService::class,
        'music_generation' => MarketMusicAppRuntimeService::class,
        'file_qa' => MarketFileQaAppRuntimeService::class,
        MarketSeedSvcAppRuntimeService::UPSTREAM_APP_CODE => MarketSeedSvcAppRuntimeService::class,
        MarketGeoAppRuntimeService::UPSTREAM_APP_CODE => MarketGeoAppRuntimeService::class,
        MarketGenericImageAppRuntimeService::UPSTREAM_APP_CODE => MarketGenericImageAppRuntimeService::class,
    ];

    private const MODEL_PROTOCOLS = [
        'image'=>['native_v1','flat_image_v1','image_messages_v1','qwen_image_v1'],
        'video'=>['native_v1','flat_video_v1','video_input_parameters_v1','wan_video_media_v1','h3_content_v1','q4_video_v1','veo_video_v1'],
    ];
    private const APPLICATION_PROTOCOLS = [
        'nano_banana'=>['nano_banana_v1'], 'seedance'=>['seedance_content_v1'],
        'wan'=>['wan_auto_v1','wan_reference_v1','wan_edit_v1','wan_text_v1'],
        'grok_video'=>['grok_video_v1'], 'happy_horse'=>['happy_horse_media_v1'], 'full_video'=>['full_video_content_v1'],
    ];

    /** Serializer selection is separate from capability, material and SKU validation. */
    public static function modelProtocol(string $type, array $snapshot): string
    {
        $source=MarketGenerationSchema::array($snapshot['source_payload'] ?? []);
        $metadata=MarketGenerationSchema::array($snapshot['market_metadata'] ?? $source['market_metadata'] ?? []);
        $declared=self::declaredProtocol($snapshot,$metadata);
        if ($declared !== null && !in_array($declared,self::MODEL_PROTOCOLS[$type] ?? [],true)) throw new InvalidArgumentException('模型生成协议未注册或类型不匹配');
        // A serializer hint must not bypass a model's material contract.
        if (MarketGenerationMaterials::contract($metadata)['modes'] ?? []) return 'native_v1';
        if ($declared === 'native_v1') throw new InvalidArgumentException('原生生成协议缺少素材合同');
        if ($declared !== null) return $declared;
        $code=strtolower(trim((string)($snapshot['model_code'] ?? $snapshot['upstream_model_code'] ?? '')));
        $schema=MarketGenerationSchema::properties(MarketGenerationSchema::array($metadata['params_schema'] ?? $snapshot['params_schema'] ?? []));
        if ($type === 'image') {
            if (in_array($code,['qwen-image-3.0','qwen-image-3.0-pro'],true)) return 'qwen_image_v1';
            $doc=strtolower((string)($metadata['developer_doc_content'] ?? $metadata['api_doc'] ?? ''));
            return (isset($schema['input'],$schema['parameters']) || (str_contains($doc,'input.messages') && str_contains($doc,'parameters.')))
                ? 'image_messages_v1' : 'flat_image_v1';
        }
        if ($type !== 'video') throw new InvalidArgumentException('该类型尚未注册生成协议');
        if ($code === 'q4-preview-video') return 'q4_video_v1';
        foreach ([$code,$snapshot['channel_code'] ?? $snapshot['upstream_channel_code'] ?? '',$snapshot['name'] ?? ''] as $value) {
            $value=strtolower(trim((string)$value));$compact=preg_replace('/[^a-z0-9]+/','',$value) ?: '';
            if (preg_match('/(?:^|[-_\s])h3(?:[-_\s]|$)/',$value) || str_starts_with($compact,'h3')) return 'h3_content_v1';
        }
        if ($code === 'wan3.0-video' || strtolower((string)($snapshot['channel_code'] ?? $snapshot['upstream_channel_code'] ?? '')) === 'wan3.0-video') return 'wan_video_media_v1';
        if (str_starts_with($code,'veo3.1-')) return 'veo_video_v1';
        return isset($schema['input'],$schema['parameters']) ? 'video_input_parameters_v1' : 'flat_video_v1';
    }

    public static function applicationProtocol(string $app, array $snapshot): string
    {
        $source=MarketGenerationSchema::array($snapshot['source_payload'] ?? []);
        $metadata=MarketGenerationSchema::array($snapshot['market_metadata'] ?? $source['market_metadata'] ?? []);
        $profiles=self::APPLICATION_PROTOCOLS[$app] ?? [];
        if ($profiles === []) throw new InvalidArgumentException('应用生成协议未注册');
        $declared=self::declaredProtocol($snapshot,$metadata);
        if ($declared !== null && !in_array($declared,$profiles,true)) throw new InvalidArgumentException('应用生成协议未注册或不属于当前应用');
        return $declared ?? $profiles[0];
    }

    private static function declaredProtocol(array $snapshot, array $metadata): ?string
    {
        $locked=MarketGenerationSchema::array($snapshot['locked_params'] ?? []);
        foreach ([[$snapshot,'generation_protocol'],[$locked,'_generation_protocol'],[$metadata,'generation_protocol'],
            [MarketGenerationSchema::array($metadata['upstream_metadata'] ?? []),'generation_protocol']] as [$source,$key]) {
            if (!array_key_exists($key,$source)) continue;
            if (!is_string($source[$key]) || $source[$key] === '') throw new InvalidArgumentException('生成协议声明格式不正确');
            return $source[$key];
        }
        return null;
    }

    public static function runtime(string $type, array $selection): string
    {
        $selection = array_replace(MarketGenerationSchema::array($selection['params'] ?? []), $selection);
        foreach (['model_id', 'channel', 'image_model_id', 'video_model_id'] as $key) {
            $id = (string)($selection[$key] ?? '');
            if (($type === 'video' && preg_match('/^market_(?:image_model|nano_banana):/', $id))
                || ($type === 'image' && preg_match('/^market_video_(?:model|app):/', $id))) {
                throw new InvalidArgumentException('所选市场资源不属于当前生成类型');
            }
        }
        if (self::resourceType($selection) === 'model') {
            if (!empty($selection['model_type']) && $selection['model_type'] !== $type) throw new InvalidArgumentException('模型类型与当前工具不一致');
            return self::MODELS[$type] ?? throw new InvalidArgumentException('该类型尚未注册模型适配器');
        }
        if ($type === 'video') {
            $app = self::appCode($selection);
            if ($app !== '' && !self::supportsVideoApplication($app)) throw new InvalidArgumentException('该视频应用尚未注册适配器');
            return MarketVideoAppRuntimeService::class;
        }
        $adapter = self::application($selection);
        if ($type === 'image' && !in_array($adapter, [MarketNanoBananaAppRuntimeService::class, MarketGenericImageAppRuntimeService::class], true)) {
            throw new InvalidArgumentException('该应用不能用于图片生成');
        }
        return $adapter;
    }

    public static function application(array $selection): string
    {
        $app = self::appCode($selection);
        if ($app === 'music_generation' && ($selection['runtime_adapter'] ?? '') === 'music_operation') return MarketMusicOperationRuntimeService::class;
        if (isset(self::VIDEO_APPS[$app])) return MarketVideoAppRuntimeService::class;
        if (isset(self::APPLICATIONS[$app])) return self::APPLICATIONS[$app];
        // Compatibility identities from already saved selections, never a PHP
        // class name or endpoint supplied by a browser.
        if (MarketNanoBananaAppRuntimeService::isSelection($selection)) return MarketNanoBananaAppRuntimeService::class;
        if (MarketGeoAppRuntimeService::isSelection($selection)) return MarketGeoAppRuntimeService::class;
        if (($selection['runtime_adapter'] ?? '') === 'generic_image') return MarketGenericImageAppRuntimeService::class;
        throw new InvalidArgumentException('No market application API adapter is registered for this selection');
    }

    public static function resourceType(array $selection): string
    {
        $explicit = (string)($selection['resource_type'] ?? '');
        if ($explicit !== '' && !in_array($explicit, ['model', 'app_api'], true)) throw new InvalidArgumentException('市场资源类型不正确');
        $identity = '';
        foreach (['model_id', 'channel', 'image_model_id', 'video_model_id'] as $key) {
            $value = (string)($selection[$key] ?? '');
            $candidate = preg_match('/^market_(?:image|video)_model:/', $value) ? 'model'
                : (preg_match('/^market_(?:video_app|nano_banana):/', $value) ? 'app_api' : '');
            if ($candidate !== '' && $identity !== '' && $candidate !== $identity) throw new InvalidArgumentException('市场模型与应用选择不一致');
            if ($candidate !== '') $identity = $candidate;
        }
        if ($explicit !== '' && $identity !== '' && $explicit !== $identity) throw new InvalidArgumentException('市场资源类型与所选模型不一致');
        return $explicit ?: ($identity ?: (self::appCode($selection) !== '' ? 'app_api' : 'model'));
    }

    /** Relative, credential-free HTTP plan for a trusted resolved snapshot. */
    public static function transport(array $snapshot, string $operation, string $taskId = ''): array
    {
        if (!in_array($operation, ['submit', 'query'], true)) throw new InvalidArgumentException('不支持的市场适配操作');
        if (self::resourceType($snapshot) === 'model') {
            return ['method' => $operation === 'submit' ? 'POST' : 'GET',
                'path' => '/api/v1/tasks' . ($operation === 'query' ? '/' . rawurlencode($taskId) : ''), 'payload' => []];
        }
        $app = self::appCode($snapshot);
        if (!isset(self::VIDEO_APPS[$app]) && $app !== 'nano_banana') throw new InvalidArgumentException('该应用尚未注册生成协议');
        $api = $operation === 'query' ? 'query' : (string)($snapshot['api_code'] ?? 'submit');
        $path = '/api/v1/apps/' . rawurlencode($app) . '/' . rawurlencode($api);
        $method = $operation === 'submit' ? 'POST' : (self::VIDEO_APPS[$app]['query'] ?? 'GET');
        if ($operation === 'query' && $method === 'GET') $path .= '?task_id=' . rawurlencode($taskId);
        return ['method' => $method, 'path' => $path, 'payload' => $operation === 'query' && $method === 'POST' ? ['task_id' => $taskId] : []];
    }

    public static function requiresAssetPreparation(array $snapshot): bool
    {
        return self::resourceType($snapshot) === 'app_api' && !empty(self::VIDEO_APPS[self::appCode($snapshot)]['assets']);
    }

    public static function supportsVideoApplication(string $app): bool
    {
        return isset(self::VIDEO_APPS[$app]);
    }

    private static function appCode(array $selection): string
    {
        return (string)($selection['upstream_app_code'] ?? $selection['app_code'] ?? $selection['application_code'] ?? '');
    }
}
