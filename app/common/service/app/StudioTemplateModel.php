<?php
declare(strict_types=1);

namespace app\common\service\app;

use InvalidArgumentException;

/** Picks a tenant model for a draft. Generation still checks the live selection. */
final class StudioTemplateModel
{
    public static function pick(string $original, string $default, callable $find, callable $candidates, callable $matches): array
    {
        if ($original !== '' && ($model = $find($original))) {
            return [$model, 'original'];
        }
        foreach ($candidates() as $id) {
            if ($id === $original) {
                continue;
            }
            $model = $find($id);
            if ($model && $matches($model)) {
                return [$model, 'compatible'];
            }
        }
        return [$default !== '' ? $find($default) : null, 'default'];
    }

    /** Resolve against the app's own catalog and material rules. */
    public static function video(int $tenant, array $body, string $default, string $catalog, callable $validate): array
    {
        $original = $body['video_selection'];
        $models = [];
        $find = static function (string $id) use ($catalog, $tenant, &$models): ?array {
            if (!array_key_exists($id, $models)) {
                $models[$id] = null;
                foreach ($catalog::models($tenant, $id) as $model) {
                    if ((string)$model['id'] === $id) {
                        $models[$id] = $model;
                        break;
                    }
                }
            }
            return $models[$id];
        };
        $issue = static function (array $model, array $draft) use ($validate): string {
            try {
                $validate($model, $draft);
                return '';
            } catch (InvalidArgumentException $e) {
                return $e->getMessage();
            }
        };
        [$model, $source] = self::pick(
            (string)($original['model_id'] ?? ''),
            $default,
            $find,
            static fn() => array_map('strval', array_column($catalog::models($tenant), 'id')),
            static fn(array $candidate) => $issue($candidate, $body) === ''
        );
        if (!$model) {
            $body['video_selection']['model_id'] = '';
            return [$body, '模板已填入。当前没有可用的模板模型或应用默认模型，请选择视频模型。', false];
        }

        $body['video_selection']['model_id'] = (string)$model['id'];
        $specs = (($body['mode'] ?? '') === 'normal' && ($body['reference_mode'] ?? '') === 'start_end')
            ? ($model['start_end_specs'] ?? [])
            : ($model['specs'] ?? []);
        $changes = [];
        $labels = ['quality' => '清晰度', 'ratio' => '比例', 'duration' => '时长'];
        if ($specs) {
            // Keep the script's timing first, then the layout and resolution.
            $rank = static fn(array $spec) => [
                abs((int)$spec['duration'] - (int)($original['duration'] ?? 0)),
                $spec['ratio'] !== ($original['ratio'] ?? null),
                $spec['quality'] !== ($original['quality'] ?? null),
            ];
            $best = $specs[0];
            foreach ($specs as $spec) {
                if ($rank($spec) < $rank($best)) {
                    $best = $spec;
                }
            }
            foreach ($labels as $key => $label) {
                if ($best[$key] !== ($key === 'duration' ? (int)($original[$key] ?? 0) : ($original[$key] ?? null))) {
                    $changes[] = $label;
                }
                $body['video_selection'][$key] = $best[$key];
            }
        }
        if (($body['audio'] ?? null) !== null && empty($model['supports_generate_audio'])) {
            $body['audio'] = null;
            $changes[] = '声音';
        }

        $note = match ($source) {
            'compatible' => '已选用兼容模型。',
            'default' => '已选用当前应用的默认模型。',
            default => '',
        };
        if ($changes) {
            $note .= '已调整' . implode('、', $changes) . '，请检查参数和原脚本。';
        }
        $problem = !$specs
            ? '当前模型不支持模板的生成方式，请调整生成方式或选择其他模型。'
            : $issue($model, $body);
        if ($problem !== '') {
            $note .= str_ends_with($problem, '。') ? $problem : $problem . '。';
        }
        return [$body, $note, !$changes && $problem === ''];
    }
}
