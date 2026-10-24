<?php

namespace app\common\service\app\aigc_canvas;

use InvalidArgumentException;

/** Canvas-only editable controls. Transport, pricing and model identity never come from this form. */
final class CanvasGenerationParameters
{
    private const FIELDS = [
        'mask_url' => ['重绘遮罩', 'string'],
        'service_tier' => ['服务档位', 'string'], 'tools' => ['联网搜索', 'array'],
        'audio_setting' => ['原视频音频处理', 'string'], 'biz_id' => ['业务标识', 'string'],
        'payload' => ['业务关联数据', 'string'], 'meta_data' => ['视频元数据', 'string'],
        'seed' => ['随机种子', 'integer'], 'quality' => ['渲染质量', 'string'],
        'background' => ['背景', 'string'], 'negative_prompt' => ['反向提示词', 'string'],
        'watermark' => ['水印', 'boolean'], 'aigc_watermark' => ['生成标识', 'boolean'],
        'audio' => ['生成音频', 'boolean'], 'generate_audio' => ['生成音频', 'boolean'],
        'is_rec' => ['提示词增强', 'boolean'], 'prompt_extend' => ['提示词扩展', 'boolean'],
        'prompt_extend_mode' => ['提示词扩展方式', 'string'], 'enhance_prompt' => ['提示词增强', 'boolean'],
        'camera_fixed' => ['固定镜头', 'boolean'], 'wm_position' => ['水印位置', 'integer'],
        'wm_url' => ['自定义水印地址', 'string'], 'draft' => ['草稿模式', 'boolean'],
        'frames' => ['帧数', 'integer'], 'fps' => ['帧率', 'integer'],
        'motion_bucket_id' => ['运动强度', 'integer'], 'cfg_scale' => ['提示词引导强度', 'number'],
        'style_influence' => ['风格强度', 'number'], 'weirdness' => ['创意强度', 'number'],
        'audio_weight' => ['参考音频权重', 'number'], 'style_negative' => ['排除风格', 'string'],
        'lyric_prompt' => ['歌词提示词', 'string'], 'persona_id' => ['声音风格 ID', 'string'],
        'variation_category' => ['变化强度', 'string'],
        'max_tokens' => ['最大输出长度', 'integer'], 'temperature' => ['随机性', 'number'],
        'top_p' => ['采样范围', 'number'], 'presence_penalty' => ['话题惩罚', 'number'],
        'frequency_penalty' => ['重复惩罚', 'number'], 'enable_thinking' => ['深度思考', 'boolean'],
        'reasoning_effort' => ['推理强度', 'string'],
    ];

    public static function definitions(array $model, string $type): array
    {
        if (in_array($type, ['image','video'], true) && (!empty($model['material_modes']) || (!isset($model['params_schema']['input']) && !isset($model['params_schema']['parameters'])))) return \app\common\service\power\MarketGenerationParameters::definitions($model);
        $schema = self::arrayValue($model['params_schema'] ?? []);
        $properties = self::arrayValue($schema['properties'] ?? $schema);
        $defaults = self::arrayValue($model['default_params'] ?? []);
        if ($type === 'text' && (int)($model['max_output_tokens'] ?? 0) > 0) {
            $properties['max_tokens'] = ['type' => 'integer', 'minimum' => 1, 'maximum' => (int)$model['max_output_tokens']];
        }
        $definitions = [];
        foreach (['' => $properties, 'parameters.' => self::children($properties['parameters'] ?? []), 'input.' => self::children($properties['input'] ?? []), 'metadata.' => self::children($properties['metadata'] ?? [])] as $prefix => $fields) {
            foreach ($fields as $key => $definition) {
                if (!isset(self::FIELDS[$key]) || !is_array($definition)) continue;
                if ($type === 'music' && in_array($key, ['audio_weight', 'variation_category'], true) && ($model['locked_params']['type'] ?? 'generate') === 'generate') continue;
                if ($key === 'quality' && $type !== 'image') continue; // Existing resolution/SKU selector owns video quality.
                [$label, $fallbackType] = self::FIELDS[$key];
                $valueType = $definition['type'] ?? $fallbackType;
                if (!in_array($valueType, ['string', 'number', 'integer', 'boolean', 'array', 'object'], true)) continue;
                if ($valueType === 'number' && str_contains((is_string($definition['options'] ?? null) ? $definition['options'] : ''), '整数')) $valueType = 'integer';
                $path = $prefix . $key;
                if ($key === 'tools') $valueType = 'array';
                $field = ['key' => $path, 'label' => $label, 'type' => $valueType,
                    'description' => (string)($definition['description'] ?? ''), 'options' => self::options($definition)];
                if ($key === 'mask_url' || $key === 'wm_url') $field['control'] = 'image';
                if ($key === 'tools') $field['control'] = 'web_search';
                if ($key === 'meta_data') $field['format'] = 'json';
                foreach (['minimum', 'maximum', 'minLength', 'maxLength', 'minItems', 'maxItems'] as $constraint) {
                    if (isset($definition[$constraint]) && is_numeric($definition[$constraint])) $field[$constraint] = 0 + $definition[$constraint];
                }
                // Some synchronized schemas express numeric ranges as options text.
                if (in_array($valueType, ['integer', 'number'], true) && preg_match('/(-?\d+(?:\.\d+)?)\s*[～~至-]\s*(-?\d+(?:\.\d+)?)/u', (is_string($definition['options'] ?? null) ? $definition['options'] : ''), $range)) {
                    $field['minimum'] ??= 0 + $range[1]; $field['maximum'] ??= 0 + $range[2]; $field['options'] = [];
                }
                $default = self::at($defaults, $path) ?? ($definition['default'] ?? null);
                if ($default !== null && $default !== '') {
                    try { $field['default'] = self::value($default, $field); } catch (InvalidArgumentException) { /* A malformed catalogue default is not a user override. */ }
                }
                $locked = self::locked($model, $path);
                if ($locked !== null) { $field['locked'] = true; $field['default'] = $locked; }
                $definitions[] = $field;
            }
        }
        return $definitions;
    }

    /** Called before prompt enrichment, task creation, billing or provider submission. */
    public static function apply(array $request, array $model, string $type): array
    {
        if (!isset($request['advanced_params']) || $request['advanced_params'] === []) return $request;
        if (!is_array($request['advanced_params'])) throw new InvalidArgumentException('高级参数格式不正确');
        $definitions = array_column(self::definitions($model, $type), null, 'key');
        foreach ($request['advanced_params'] as $path => $raw) {
            if (!isset($definitions[$path])) throw new InvalidArgumentException('当前模型不支持参数：' . $path . '，请重置高级参数');
            $field = $definitions[$path];
            $value = self::value($raw, $field);
            if (isset($field['locked']) && $value != $field['default']) throw new InvalidArgumentException($field['label'] . '已由当前规格固定');
            $parts = explode('.', $path);
            $key = end($parts);
            if ($type === 'text') {
                $request['model_config'][$key] = $value;
                if ($key === 'max_tokens') $request['max_tokens'] = $value;
            } else {
                if (count($parts) === 2) {
                    $request['provider_params'][$parts[0]][$parts[1]] = $value;
                    if ($parts[0] === 'metadata') $request['metadata'][$parts[1]] = $value;
                }
                else $request['provider_params'][$key] = $value;
                // Video adapters read flat options; image quality must remain separate from billing resolution.
                if (($type === 'video' && ($parts[0] ?? '') !== 'metadata') || $key === 'negative_prompt') $request[$key] = $value;
            }
        }
        return $request;
    }

    private static function children($definition): array
    {
        if (!is_array($definition)) return [];
        $children = self::arrayValue($definition['properties'] ?? $definition['children'] ?? []);
        // Legacy market schemas document structured fields only in a typed object's example.
        foreach (self::arrayValue($definition['example'] ?? []) as $key => $value) {
            if (isset(self::FIELDS[$key]) && !isset($children[$key])) $children[$key] = ['type' => self::FIELDS[$key][1]];
        }
        return $children;
    }

    private static function options(array $definition): array
    {
        $raw = $definition['enum'] ?? $definition['options'] ?? [];
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : (str_contains($raw, '/') || str_contains($raw, '|') ? preg_split('~\s*[/|]\s*~', $raw) : []);
        }
        return array_values(array_filter(array_map(static fn($v) => is_array($v) ? ($v['value'] ?? null) : $v, (array)$raw), static fn($v) => is_scalar($v) && $v !== ''));
    }

    private static function value($value, array $field)
    {
        $error = $field['label'] . '格式或范围不正确';
        if ($field['type'] === 'boolean') {
            if (!is_scalar($value) || $value === '') throw new InvalidArgumentException($error);
            $value = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($value === null) throw new InvalidArgumentException($error);
        } elseif (in_array($field['type'], ['integer', 'number'], true)) {
            if (is_bool($value) || !is_numeric($value) || !is_finite((float)$value) || ($field['type'] === 'integer' && (float)$value != (int)$value)) throw new InvalidArgumentException($error);
            $value = $field['type'] === 'integer' ? (int)$value : (float)$value;
            if ((isset($field['minimum']) && $value < $field['minimum']) || (isset($field['maximum']) && $value > $field['maximum'])) throw new InvalidArgumentException($error);
        } elseif (in_array($field['type'], ['array','object'], true)) {
            if (!is_array($value)) throw new InvalidArgumentException($error);
            if ($field['type'] === 'array' && !array_is_list($value)) throw new InvalidArgumentException($error);
            if (($field['control'] ?? '') === 'web_search' && $value !== [] && $value !== [['type'=>'web_search']]) throw new InvalidArgumentException('仅支持联网搜索工具');
        } else {
            if (!is_string($value)) throw new InvalidArgumentException($error);
            $length = mb_strlen($value);
            if ($length < ($field['minLength'] ?? 0) || $length > ($field['maxLength'] ?? 20000)) throw new InvalidArgumentException($error);
            if (($field['control'] ?? '') === 'image' && $value !== '' && (!filter_var($value, FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($value, PHP_URL_SCHEME)), ['http','https'], true))) throw new InvalidArgumentException($field['label'] . '须为 HTTP 或 HTTPS 地址');
            if (($field['format'] ?? '') === 'json' && $value !== '') { json_decode($value); if (json_last_error() !== JSON_ERROR_NONE) throw new InvalidArgumentException('元数据必须是有效 JSON'); }
        }
        if ($field['options'] !== [] && !in_array(is_bool($value) ? ($value ? 'true' : 'false') : (string)$value, array_map(static fn($v) => is_bool($v) ? ($v ? 'true' : 'false') : (string)$v, $field['options']), true)) throw new InvalidArgumentException($error);
        return $value;
    }

    private static function locked(array $model, string $path)
    {
        return self::at(self::arrayValue($model['locked_params'] ?? []), $path);
    }

    private static function at(array $values, string $path)
    {
        foreach (explode('.', $path) as $part) { if (!is_array($values) || !array_key_exists($part, $values)) return null; $values = $values[$part]; }
        return $values;
    }

    private static function arrayValue($value): array { return is_array($value) ? $value : (is_string($value) ? (array)json_decode($value, true) : []); }
}
