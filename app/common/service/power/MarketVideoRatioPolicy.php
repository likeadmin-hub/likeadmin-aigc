<?php

namespace app\common\service\power;

/** Adapt a composed app's preferred ratio only to an active, reviewed native equality rule. */
final class MarketVideoRatioPolicy
{
    /** Publish restrictions only when the selected material view proves the rule's conditions. */
    public static function modeSchema(array $metadata, array $mode, array $schema): array
    {
        $present = []; $fields = [];
        foreach ((array)($mode['fields'] ?? []) as $field) {
            $fields[] = $field['field'];
            if (!empty($field['required'])) $present[$field['field']] = true;
        }
        foreach (MarketGenerationNativeContract::rules($metadata, (string)($mode['code'] ?? '')) as $rule) {
            if (($rule['kind'] ?? '') !== 'equals' || !in_array($rule['field'] ?? '', ['ratio', 'aspect_ratio'], true)) continue;
            $active = true;
            foreach ($rule['when_present'] ?? [] as $key) if (!isset($present[$key])) $active = false;
            foreach ($rule['when_absent'] ?? [] as $key) if (in_array($key, $fields, true)) $active = false;
            foreach ($rule['when'] ?? [] as $key => $value) if ($key !== 'generation_type' || ($mode['code'] ?? '') !== $value) $active = false;
            if ($active && isset($schema['properties'][$rule['field']])) {
                $schema['properties'][$rule['field']]['enum'] = [$rule['value']];
                $schema['properties'][$rule['field']]['default'] = $rule['value'];
            }
        }
        return $schema;
    }

    public static function normalize(array $metadata, array $request): array
    {
        $requested = (string)($request['ratio'] ?? $request['aspect_ratio'] ?? '');
        $mode = MarketGenerationMaterials::select($metadata, $request);
        $native = (string)($mode['native_mode'] ?? '');
        $values = array_replace((array)($request['provider_params'] ?? []), MarketGenerationMaterials::serialize($metadata, $request));
        $resolved = $requested;
        foreach (MarketGenerationNativeContract::rules($metadata, $native) as $rule) {
            if (($rule['kind'] ?? '') !== 'equals' || !in_array($rule['field'] ?? '', ['ratio', 'aspect_ratio'], true)) continue;
            $active = true;
            foreach ($rule['when'] ?? [] as $key => $value) if (($values[$key] ?? null) !== $value) $active = false;
            foreach ($rule['when_present'] ?? [] as $key) if (empty($values[$key])) $active = false;
            foreach ($rule['when_absent'] ?? [] as $key) if (!empty($values[$key])) $active = false;
            if ($active && is_string($rule['value'] ?? null)) $resolved = $rule['value'];
        }
        $schema = (array)($mode['parameter_schema'] ?? MarketGenerationNativeContract::schema($metadata, $native));
        $properties = MarketGenerationSchema::properties($schema);
        $definition = $properties['aspect_ratio'] ?? $properties['ratio'] ?? [];
        $choices = MarketGenerationSchema::choices($definition);
        if ($choices !== [] && !in_array($resolved, $choices, true)) {
            if (in_array('adaptive', $choices, true)) $resolved = 'adaptive';
            elseif (count($choices) === 1) $resolved = (string)$choices[0];
            elseif (isset($definition['default']) && in_array($definition['default'], $choices, true)) $resolved = (string)$definition['default'];
            else throw new \InvalidArgumentException('当前生成方式不支持项目比例，请切换模型');
        }
        $applied = $resolved !== $requested;
        return ['ratio' => $resolved, 'requested_ratio' => $requested, 'resolved_ratio' => $resolved,
            'ratio_fallback' => ['applied' => $applied, 'message' => $applied
                ? '当前生成方式要求使用' . ($resolved === 'adaptive' ? '自适应比例，将跟随参考图片比例生成' : $resolved . '比例生成') : '']];
    }
}
