<?php

namespace app\common\service\app\aigc_short_drama;

use think\facade\Db;

/** Resolve defaults at read time, including children created before the first image selection. */
class ShortDramaImageDefaults
{
    public static function resolve(array $project, array $settings): array
    {
        $scope = ['tenant_id' => (int)($project['tenant_id'] ?? 0), 'user_id' => (int)($project['user_id'] ?? 0), 'delete_time' => 0];
        $id = (int)($project['id'] ?? 0);
        if (!$id || !$scope['tenant_id'] || !$scope['user_id'] || !empty($settings['image_selection_explicit'])) return $settings;
        $episode = Db::name('aigc_short_drama_episode_task')->where($scope)->where('production_project_id', $id)->find();
        if (!$episode) return $settings;
        $first = Db::name('aigc_short_drama_episode_task')->where($scope)
            ->where('project_id', (int)$episode['project_id'])->order('episode_number')->find();
        if (!$first || (int)$first['id'] === (int)$episode['id']) return $settings;
        // Old workspaces with actual image requests retain their chosen model.
        // New workspaces carry an explicit marker and do not need this legacy lookup.
        if (!array_key_exists('image_selection_explicit', $settings)
            && Db::name('aigc_short_drama_generation_task')->where($scope)->where('project_id', $id)
                ->whereIn('task_type', ['subject_image', 'scene_image', 'three_view', 'shot_image'])->count() > 0) return $settings;
        $sourceId = (int)($first['production_project_id'] ?? 0) ?: (int)$episode['project_id'];
        $source = Db::name('aigc_short_drama_project')->where($scope)->where('id', $sourceId)->find();
        $sourceSettings = json_decode((string)($source['generation_settings_json'] ?? ''), true) ?: [];
        $image = (array)($sourceSettings['image'] ?? []);
        if (self::modelId($image) === '') return $settings;
        // Inherit the model and quality, never another episode's aspect ratio or video settings.
        unset($image['ratio'], $image['aspect_ratio']);
        $ratio = (string)($settings['ratio'] ?? $settings['aspect_ratio'] ?? $project['ratio'] ?? '');
        if ($ratio !== '') $image = array_merge($image, ['ratio' => $ratio, 'aspect_ratio' => $ratio]);
        $settings['image'] = $image;
        return $settings;
    }

    public static function modelId(array $image): string
    {
        foreach (['model_id', 'image_model_id', 'id', 'value', 'channel_code', 'channel'] as $key) {
            if (trim((string)($image[$key] ?? '')) !== '') return trim((string)$image[$key]);
        }
        return '';
    }

    public static function changed(array $before, array $after): bool
    {
        return self::modelId($before) !== self::modelId($after)
            || (string)($before['resolution'] ?? $before['quality'] ?? '') !== (string)($after['resolution'] ?? $after['quality'] ?? '');
    }

    /** The same defaults feed quoting and submission. An explicit request always wins. */
    public static function request(array $params, array $settings): array
    {
        $nested = is_array($params['params'] ?? null) ? $params['params'] : [];
        $explicit = self::modelId($params) ?: self::modelId($nested);
        if ($explicit !== '' || !empty($params['model_code']) || !empty($nested['model_code'])) return $params;
        $image = (array)($settings['image'] ?? []);
        $model = self::modelId($image);
        if ($model === '') return $params;
        $params['model_id'] = $params['image_model_id'] = $model;
        if (empty($params['resolution']) && empty($params['quality']) && empty($nested['resolution']) && empty($nested['quality'])) {
            $params['resolution'] = $params['quality'] = (string)($image['resolution'] ?? $image['quality'] ?? '');
        }
        return $params;
    }
}
