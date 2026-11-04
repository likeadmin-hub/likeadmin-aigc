<?php
namespace app\common\service\app\aigc_micro_video;

use InvalidArgumentException;

/** Market capabilities decide which models can run; tenant lists may narrow that set. */
final class MicroVideoModelOptions
{
    public static function scope(array $stored): string
    {
        return $stored['model_scope'] ?? ((isset($stored['video_model_ids']) || isset($stored['frame_model_ids'])) ? 'restricted' : 'market');
    }

    public static function videos(int $tenant, array $config): array
    {
        $allowed = $config['model_scope'] === 'restricted' ? $config['video_model_ids'] : null;
        return MicroVideoCatalog::models($tenant, false, 'reference', $allowed);
    }

    public static function images(array $catalog, array $config, int $minimumReferences): array
    {
        return array_values(array_filter($catalog, static fn(array $model): bool =>
            $model['max_reference_images'] >= $minimumReferences
            && ($config['model_scope'] !== 'restricted' || in_array($model['id'], $config['frame_model_ids'], true))
        ));
    }

    /** An explicit choice must still be callable. Only preferences may fall back. */
    public static function pick(array $models, string $requested, string $preferred): array
    {
        $byId = array_column($models, null, 'id');
        if ($requested !== '') {
            if (!isset($byId[$requested])) throw new InvalidArgumentException('所选模型已不可用，请重新选择');
            return $byId[$requested];
        }
        return $byId[$preferred] ?? $models[0] ?? [];
    }

    public static function defaultSpec(array $model, string $quality, string $ratio): array
    {
        $specs = $model['specs'] ?? [];
        foreach ($specs as $spec) if ($spec['quality'] === $quality && $spec['ratio'] === $ratio) return $spec;
        foreach ($specs as $spec) if ($spec['ratio'] === $ratio) return $spec;
        return $specs[0] ?? [];
    }
}
