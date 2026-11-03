<?php

namespace app\common\service\app\aigc_short_drama;

use app\common\service\power\MarketCreationParameters;

/** Keep model controls through storyboard prompt assembly; the runtime owns their schema and SKU locks. */
final class ShortDramaVideoModelParameters
{
    public static function normalize(int $tenantId, array $params): array
    {
        $nested = is_array($params['params'] ?? null) ? $params['params'] : [];
        foreach (['advanced_params', 'provider_params'] as $key) {
            if (!array_key_exists($key, $params) && array_key_exists($key, $nested)) {
                $params[$key] = $nested[$key];
            }
        }
        if (!isset($params['model_id'])) {
            $params['model_id'] = $params['video_model_id'] ?? $nested['model_id'] ?? $nested['video_model_id'] ?? '';
        }
        return MarketCreationParameters::apply($tenantId, $params, 'video');
    }

    /** Business-owned prompt, duration, identity and references must be overlaid after these options. */
    public static function carry(array $params): array
    {
        $result = [];
        foreach (['advanced_params', 'provider_params'] as $key) {
            if (isset($params[$key]) && is_array($params[$key]) && $params[$key] !== []) {
                $result[$key] = $params[$key];
            }
        }
        // Legacy adapters read the flat mirrors emitted by the shared control
        // validator; native adapters read provider_params, including objects.
        foreach (array_keys((array)($result['advanced_params'] ?? [])) as $path) {
            $parts = explode('.', $path);
            $key = end($parts);
            if (array_key_exists($key, $params)) $result[$key] = $params[$key];
        }
        // A storyboard task describes one shot. Declare this business choice
        // explicitly: some models require it and provide no supplier default.
        // Undeclared fields are still removed by the shared model encoder.
        $result['multi_shots'] = $params['multi_shots'] ?? $result['provider_params']['multi_shots'] ?? false;
        return $result;
    }
}
