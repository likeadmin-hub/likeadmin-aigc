<?php

namespace app\common\service\app\aigc_short_drama\canvas;

/**
 * Registry deliberately accepts no arbitrary configured class names. Adding a
 * real provider requires a reviewed first-party adapter and a new registry case.
 */
final class CanvasExecutionRuntime
{
    public static function provider(int $tenantId, int $userId): CanvasExecutionProviderInterface
    {
        return match ((string)config('short_drama_canvas.execution_provider', 'unavailable')) {
            'short_drama_resources' => new ShortDramaCanvasExecutionProvider($tenantId, $userId),
            'unavailable' => new UnavailableCanvasExecutionProvider(),
            default => new UnavailableCanvasExecutionProvider(),
        };
    }

    public static function assertReady(int $tenantId, int $userId): CanvasExecutionProviderInterface
    {
        if (!(bool)config('short_drama_canvas.execution_ready', false)) {
            CanvasPolicy::fail('EXECUTION_NOT_READY', '短剧画布 Agent 尚未开放，当前可继续保存画布和想法');
        }
        $provider = self::provider($tenantId, $userId);
        if (!$provider->isReady()) CanvasPolicy::fail('EXECUTION_NOT_READY', $provider->unavailableMessage());
        return $provider;
    }
}
