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
