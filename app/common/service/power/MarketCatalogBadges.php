<?php

namespace app\common\service\power;

/** Join display metadata only. Public listings must never add a model, route, or SKU. */
final class MarketCatalogBadges
{
    public static function fields(array $row): array
    {
        $fields = [];
        if (is_array($row['marketing_tags'] ?? null)) {
            $fields['marketing_tags'] = array_values(array_unique(array_filter(
                $row['marketing_tags'], static fn ($tag) => is_string($tag) && preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $tag)
            )));
            if (in_array('new', $fields['marketing_tags'], true) && !empty($row['new_until']) && is_numeric($row['new_until'])) {
                $fields['new_until'] = (int)$row['new_until'];
            }
        }
        if (in_array($row['channel_source'] ?? null, ['official','third_party','unknown'], true)) {
            $fields['channel_source'] = $row['channel_source'];
        } elseif (in_array($row['is_official_channel'] ?? null, [true,false,0,1,'0','1'], true)) {
            $fields['channel_source'] = $row['is_official_channel'] ? 'official' : 'third_party';
        }
        return $fields;
    }

    public static function models(array $models, array $public): array
    {
        $index = [];
        foreach ($public as $row) {
            $key = self::modelKey($row, !empty($row['factory_model']));
            if ($key !== null) $index[$key] = array_key_exists($key, $index) ? [] : $row;
        }
        foreach ($models as &$model) {
            $pricing = $model['pricing']['pricing_v2'] ?? $model['pricing_v2'] ?? [];
            $key = self::modelKey($model, ($pricing['resource_type'] ?? '') === 'factory_model');
            $row = $index[$key] ?? [];
            $id = MarketGenerationSourceIdentity::modelId($model);
            if ($id > 0 && (int)($row['model_id'] ?? 0) > 0 && (int)$row['model_id'] !== $id) continue;
            $model += self::fields($model) + self::fields($row);
        }
        return $models;
    }

    private static function modelKey(array $row, bool $factory): ?string
    {
        $code = trim((string)($row['model_code'] ?? ''));
        $channel = trim((string)($row['channel_code'] ?? ''));
        if ($code === '' || (!$factory && $channel === '')) return null;
        return json_encode([$factory ? 'factory' : 'model', $code, $factory ? '' : $channel]);
    }

    public static function applications(array $apps, array $public): array
    {
        $byApp = array_column($public, null, 'code');
        foreach ($apps as &$app) {
            $match = $byApp[$app['code'] ?? ''] ?? [];
            if (!$match || !is_array($app['apis'] ?? null)) continue;
            if (!empty($match['id']) && !empty($app['id']) && (int)$match['id'] !== (int)$app['id']) continue;
            $byApi = array_column($match['apis'] ?? [], null, 'code');
            foreach ($app['apis'] as &$api) {
                $row = $byApi[$api['code'] ?? ''] ?? [];
                if (!empty($row['id']) && !empty($api['id']) && (int)$row['id'] !== (int)$api['id']) continue;
                $api += self::fields($api) + self::fields($row);
            }
            unset($api);
        }
        return $apps;
    }
}
