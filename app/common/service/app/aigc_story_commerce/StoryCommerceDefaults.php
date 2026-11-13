<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_story_commerce;

/** Preferences for new work only. Explicit tenant settings and frozen jobs retain their choices. */
final class StoryCommerceDefaults
{
    public static function planning(string $configured, array $models): string
    {
        if ($configured !== '') return $configured;
        foreach ($models as $model) {
            $code = preg_replace('/[^a-z0-9]/', '', strtolower((string)($model['model_code'] ?? $model['name'] ?? '')));
            if ($code === 'qwen37plus') return (string)$model['id'];
        }
        return (string)($models[0]['id'] ?? '');
    }

    public static function video(string $configured, array $models): string
    {
        if ($configured !== '') return $configured;
        foreach ($models as $model) {
            if (preg_match('/\bwan\s*3[.\-_ ]?0\b.*(?:高速|fast|turbo)/iu', (string)($model['label'] ?? $model['name'] ?? ''))) return (string)$model['id'];
        }
        return (string)($models[0]['id'] ?? '');
    }

    public static function style(string $configured): string
    {
        return $configured !== '' ? $configured : StoryCommercePolicy::NO_STYLE;
    }
}
