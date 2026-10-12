<?php
namespace app\common\service\open_platform;

/** Converts catalogue metadata only. Prose is never an executable schema or a bound. */
final class OpenSchemaNormalizer
{
    private const TYPES = ['object', 'array', 'string', 'integer', 'number', 'boolean', 'null'];

    public static function normalize(array $source): array
    {
        $blockers = [];
        if (!$source) return ['shape' => 'missing', 'schema' => [], 'blockers' => [self::issue('schema_missing', '$', '上游未声明请求结构')]];
        $rootType = $source['type'] ?? null;
        $jsonSchema = is_string($rootType) || is_array($rootType) && array_is_list($rootType) && !array_filter($rootType, static fn($type) => !is_string($type)) || isset($source['properties']) || is_string($source['$ref'] ?? null);
        $schema = $jsonSchema ? $source : self::fields($source, '$', $blockers, 0);
        if ($jsonSchema && !isset($schema['type']) && isset($schema['properties'])) $schema['type'] = 'object';
        self::inspect($schema, '$', $blockers, 0);
        if (($schema['type'] ?? '') !== 'object') $blockers[] = self::issue('object_schema_required', '$', '接口输入须为对象结构');
        return ['shape' => $jsonSchema ? 'json_schema' : 'field_map', 'schema' => $schema, 'blockers' => $blockers];
    }

    private static function fields(array $source, string $path, array &$blockers, int $depth): array
    {
        if ($depth > 32) { $blockers[] = self::issue('schema_depth', $path, '结构嵌套超限'); return []; }
        $schema = ['type' => 'object', 'properties' => []]; $required = [];
        foreach ($source as $name => $field) {
            if (!is_string($name) || !is_array($field)) { $blockers[] = self::issue('invalid_field_mapping', $path, '字段映射必须声明字段对象'); continue; }
            $fieldPath = $path . '.' . $name; $types = $field['type'] ?? [];
            $types = is_array($types) ? $types : preg_split('/\s*\|\s*/', trim((string)$types));
            if (!$types || array_diff($types, self::TYPES)) { $blockers[] = self::issue('unknown_schema_type', $fieldPath, '字段类型无法安全转换'); $types = []; }
            $rule = [];
            foreach (['description', 'enum', 'const', 'default', 'example', 'examples', 'format', 'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf', 'minLength', 'maxLength', 'pattern', 'minItems', 'maxItems', 'uniqueItems', 'nullable', 'additionalProperties', 'minProperties', 'maxProperties', 'oneOf', 'anyOf', 'allOf', 'not', '$ref'] as $key) if (array_key_exists($key, $field)) $rule[$key] = $field[$key];
            if ($types) $rule['type'] = count($types) === 1 ? $types[0] : array_values($types);
            if (isset($field['options']) && !array_key_exists('enum', $rule)) {
                if (is_array($field['options']) && array_is_list($field['options']) && !array_filter($field['options'], 'is_array')) $rule['enum'] = $field['options'];
                elseif (is_string($field['options']) && !preg_match('/^\s*[\[{]/',$field['options'])) $rule['description']=trim((string)($rule['description']??'')."\n".$field['options']);
                else $blockers[] = self::issue('options_review_required', $fieldPath, '非结构化 options 需明确审核，不能猜测枚举');
            }
            if (isset($field['children'])) {
                if (!is_array($field['children'])) $blockers[] = self::issue('invalid_children', $fieldPath, '嵌套字段映射无效');
                else {
                    $child = self::fields($field['children'], $fieldPath, $blockers, $depth + 1);
                    if (in_array('array', $types, true)) $rule['items'] = $child;
                    elseif (in_array('object', $types, true)) $rule = array_replace($rule, $child);
                    else $blockers[] = self::issue('children_type_mismatch', $fieldPath, 'children 须属于对象或数组');
                }
            }
            if (isset($field['properties'])) $rule['properties'] = $field['properties'];
            if (isset($field['items'])) $rule['items'] = $field['items'];
            if (is_array($field['required'] ?? null)) $rule['required'] = $field['required'];
            elseif (in_array($field['required'] ?? false, [true, 1, '1'], true)) $required[] = $name;
            elseif (!in_array($field['required'] ?? false, [false, 0, '0', '', null], true)) $blockers[] = self::issue('required_review_required', $fieldPath, 'required 取值无法安全转换');
            foreach (['default', 'example'] as $key) if (array_key_exists($key, $rule)) $rule[$key] = self::typedMetadata($rule[$key], $types);
            $schema['properties'][$name] = $rule;
        }
        if ($required) $schema['required'] = $required;
        return $schema;
    }

    private static function typedMetadata(mixed $value, array $types): mixed
    {
        if (!is_string($value) || in_array('string', $types, true)) return $value;
        if (in_array('integer', $types, true) && preg_match('/^-?(?:0|[1-9][0-9]*)$/D', $value) && filter_var($value, FILTER_VALIDATE_INT) !== false) return (int)$value;
        if (in_array('number', $types, true) && is_numeric($value) && is_finite((float)$value)) return (float)$value;
        if (in_array('boolean', $types, true) && in_array($value, ['true', 'false', '1', '0'], true)) return in_array($value, ['true', '1'], true);
        return $value;
    }

    private static function inspect(array &$schema, string $path, array &$blockers, int $depth): void
    {
        if ($depth > 32) { $blockers[] = self::issue('schema_depth', $path, '结构嵌套超限'); return; }
        if (array_key_exists('enum', $schema)) {
            $enum = $schema['enum'];
            if (is_string($enum)) {
                try { $enum = json_decode($enum, false, 32, JSON_THROW_ON_ERROR); }
                catch (\JsonException) { $enum = null; }
            }
            if (!is_array($enum) || !array_is_list($enum) || $enum === []) {
                $blockers[] = self::issue('enum_review_required', $path . '.enum', 'enum 必须为非空列表或严格 JSON 编码列表');
                // Keep the blocked schema rejecting values rather than passing
                // a string to runtime in_array() or silently dropping a constraint.
                $schema['enum'] = [];
            } else $schema['enum'] = $enum;
        }
        if (isset($schema['type']) && array_diff((array)$schema['type'], self::TYPES)) $blockers[] = self::issue('unknown_schema_type', $path, '不支持的 Schema 类型');
        if (isset($schema['$ref']) && !str_starts_with((string)$schema['$ref'], '#/')) $blockers[] = self::issue('external_schema_reference', $path, '不支持外部 Schema 引用');
        foreach ($schema['properties'] ?? [] as $name => $rule) {
            if (self::sensitiveName((string)$name)) $blockers[] = self::issue('private_schema_field', $path . '.' . $name, '输入结构含平台内部或凭据字段');
            if (is_array($rule)) self::inspect($schema['properties'][$name], $path . '.' . $name, $blockers, $depth + 1);
            else $blockers[] = self::issue('invalid_schema_field', $path . '.' . $name, '字段结构无效');
        }
        foreach (['$defs', 'definitions', 'patternProperties'] as $key) foreach ($schema[$key] ?? [] as $name => $rule) if (is_array($rule)) self::inspect($schema[$key][$name], $path . '.' . $key . '.' . $name, $blockers, $depth + 1);
        foreach (['items', 'additionalProperties', 'not'] as $key) if (isset($schema[$key]) && is_array($schema[$key])) self::inspect($schema[$key], $path . '.' . $key, $blockers, $depth + 1);
        foreach (['oneOf', 'anyOf', 'allOf'] as $key) foreach ($schema[$key] ?? [] as $i => $rule) if (is_array($rule)) self::inspect($schema[$key][$i], $path . '.' . $key . '.' . $i, $blockers, $depth + 1);
    }

    public static function sensitiveName(string $name): bool
    {
        return (bool)preg_match('/(?:credential|api[_-]?key|access[_-]?token|authorization|password|secret|upstream_url|executor_class|provider_config)/i', $name)
            || in_array(strtolower($name), ['tenant_id', 'user_id', 'price_snapshot', 'billing_override', 'executor', 'business_table', 'business_id'], true);
    }

    private static function issue(string $code, string $field, string $message): array { return compact('code', 'field', 'message'); }
}
