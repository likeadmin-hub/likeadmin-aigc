<?php
namespace app\common\service\power;

use InvalidArgumentException;

/** Pure schema operations shared by admission, immutable request snapshots and UI contracts. */
final class MarketGenerationSchema
{
    public static function array($value): array
    {
        if (is_array($value)) return $value;
        if (is_string($value)) { $decoded = json_decode($value, true); if (is_array($decoded)) return $decoded; }
        return [];
    }

    public static function properties(array $schema): array
    {
        $properties = self::array($schema['properties'] ?? $schema);
        return array_filter($properties, static fn($v, $k) => is_string($k) && is_array($v), ARRAY_FILTER_USE_BOTH);
    }

    public static function choices(array $definition): array
    {
        $raw = $definition['enum'] ?? $definition['options'] ?? $definition['values'] ?? [];
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : (preg_match('/-?\d+(?:\.\d+)?\s*(?:s|秒)?\s*[-–—~至到]\s*\d+/iu', $raw) ? [] : preg_split('/\s*(?:\/|\||,|，)\s*/u', trim($raw, "[] \t\n\r")));
        }
        $values = [];
        foreach ((array)$raw as $item) {
            $item = is_array($item) ? ($item['value'] ?? $item['code'] ?? null) : $item;
            if (!is_scalar($item)) continue;
            if (is_string($item)) $item = trim($item, "\"' \t\n\r");
            if ($item !== '' && !in_array($item, $values, true)) $values[] = $item;
        }
        return $values;
    }

    public static function durationChoices(array $definition): array
    {
        $values = self::choices($definition);
        $numericValues = array_values(array_unique(array_map('intval', array_filter($values, 'is_numeric'))));
        if ($numericValues !== []) return $numericValues;
        $min = $definition['minimum'] ?? $definition['min'] ?? null;
        $max = $definition['maximum'] ?? $definition['max'] ?? null;
        $description = (is_scalar($definition['description'] ?? null) ? (string)$definition['description'] : '') . ' ' . (is_string($definition['options'] ?? null) ? $definition['options'] : '');
        if (($min === null || $max === null) && preg_match('/(?:\[\s*(\d+)\s*,\s*(\d+)\s*\]|(\d+)\s*(?:s|秒)?\s*(?:-|–|—|~|至|到)\s*(\d+)\s*(?:s|秒)?)/iu', $description, $m)) {
            $min = $m[1] !== '' ? (int)$m[1] : (int)$m[3];
            $max = $m[2] !== '' ? (int)$m[2] : (int)$m[4];
        }
        if ($min !== null && $max !== null && $max >= $min && $max - $min <= 300) {
            $values = range(max(0, (int)$min), (int)$max);
            // A zero duration is normally a special "follow source" value, not permission for one second.
            if ((int)$min === 0 && preg_match('/[0`\s]+(?:或|or|，|,)[`\s\[]*2/iu', $description)) $values = array_values(array_diff($values, [1]));
            if (preg_match('/(?<!\d)-1\s*(?:表示|为|[:：]|智能|自动)/u', $description)) array_unshift($values, -1);
            return $values;
        }
        return [];
    }

    public static function applies(array $definition, string $mode): bool
    {
        $description = is_scalar($definition['description'] ?? null) ? (string)$definition['description'] : '';
        if ($mode === '' || !str_contains($description, '适用生成方式 ')) return true;
        return preg_match('/适用生成方式\s+' . preg_quote($mode, '/') . '(?:[（(\s]|$)/u', $description) === 1;
    }

    public static function required(array $definition, string $mode = ''): bool
    {
        $description = is_scalar($definition['description'] ?? null) ? (string)$definition['description'] : '';
        if ($mode !== '' && str_contains($description, '适用生成方式 ')) {
            return preg_match('/适用生成方式\s+' . preg_quote($mode, '/') . '[（(]必填[）)]/u', $description) === 1;
        }
        return ($definition['required'] ?? false) === true;
    }

    public static function branch(array $schema, array $values): array
    {
        $branches = $schema['oneOf'] ?? $schema['anyOf'] ?? [];
        if ($branches === []) return $schema;
        $best=null; $score=-PHP_INT_MAX;
        foreach ($branches as $branch) {
            $current=0;
            foreach ((array)($branch['required'] ?? []) as $key) $current+=isset($values[$key]) && $values[$key]!==[] && $values[$key]!=='' ? 5 : -10;
            if (($branch['additionalProperties'] ?? true) === false) foreach ($values as $key=>$value) if ($value!==[] && $value!=='' && !isset($branch['properties'][$key]) && $key!=='generation_type') $current-=10;
            if ($current>$score) { $score=$current; $best=$branch; }
        }
        if ($best===null) return $schema;
        unset($schema['oneOf'],$schema['anyOf']);
        $schema['properties']=array_replace((array)($schema['properties'] ?? []),(array)($best['properties'] ?? []));
        $schema['required']=array_values(array_unique(array_merge((array)($schema['required'] ?? []),(array)($best['required'] ?? []))));
        return $schema;
    }

    public static function normalize(array $schema, array $values, string $mode = '', bool $defaults = true): array
    {
        $result = [];
        foreach (self::properties($schema) as $name => $definition) {
            if (!self::applies($definition, $mode) || str_starts_with($name, '_')) continue;
            if (!array_key_exists($name, $values)) {
                if (!$defaults || !array_key_exists('default', $definition)) continue;
                $value = $definition['default'];
            } else $value = $values[$name];
            if ($value === null || $value === '') continue;
            $result[$name] = self::value($definition, $value, $name);
        }
        return $result;
    }

    private static function value(array $definition, $value, string $path)
    {
        $type = $definition['type'] ?? '';
        if (is_array($type)) $type = array_values(array_diff($type, ['null']))[0] ?? '';
        if ($type === 'boolean' && !is_bool($value)) {
            if (in_array($value, ['true','1',1], true)) $value = true;
            elseif (in_array($value, ['false','0',0], true)) $value = false;
        } elseif (in_array($type, ['integer','number'], true) && is_numeric($value)) {
            if ($type !== 'integer' || (float)$value === (float)(int)$value) $value = $type === 'integer' ? (int)$value : (float)$value;
        } elseif ($type === 'string' && is_scalar($value)) $value = is_bool($value) ? ($value ? 'true' : 'false') : (string)$value;
        elseif ($type === 'object' && is_array($value)) $value = self::normalize($definition, $value);
        elseif ($type === 'array' && is_array($value)) $value = array_map(static fn($v) => self::value((array)($definition['items'] ?? []), $v, $path . '[]'), array_values($value));
        $options = self::choices($definition);
        foreach ($options as $option) {
            if (is_scalar($value) && strcasecmp((string)$value, (string)$option) === 0) {
                $value = $type === 'string' ? (string)$option : ($type === 'integer' ? (int)$option : $option); break;
            }
        }
        return $value;
    }

    public static function validate(array $schema, array $values, string $mode = '', string $prefix = ''): void
    {
        if (($schema['additionalProperties'] ?? true) === false) foreach ($values as $key=>$_) if (!isset($schema['properties'][$key]) && $key!=='generation_type') throw new InvalidArgumentException('当前参数组合不支持：' . $prefix . $key);
        foreach ((array)($schema['allOf'] ?? []) as $branch) self::validate($branch,$values,$mode,$prefix);
        foreach (['oneOf','anyOf'] as $group) {
            if (empty($schema[$group])) continue;
            $matches=0;
            foreach ($schema[$group] as $branch) { try { self::validate($branch,$values,$mode,$prefix); $matches++; } catch (InvalidArgumentException $e) {} }
            if ($matches===0 || ($group==='oneOf' && $matches!==1)) throw new InvalidArgumentException('当前素材与参数组合不符合模型要求');
        }
        foreach (self::properties($schema) as $name => $definition) {
            if (!self::applies($definition, $mode) || str_starts_with($name, '_')) continue;
            $path = $prefix . $name;
            $required = self::required($definition, $mode) || in_array($name, (array)($schema['required'] ?? []), true);
            if (!array_key_exists($name, $values) || $values[$name] === null || $values[$name] === '' || $values[$name] === []) {
                if ($required) throw new InvalidArgumentException('请填写必填参数：' . $path);
                continue;
            }
            self::validateValue($definition, $values[$name], $path);
        }
    }

    private static function validateValue(array $definition, $value, string $path): void
    {
        if (isset($definition['const']) && $value !== $definition['const']) throw new InvalidArgumentException('参数选项不支持：' . $path);
        foreach ((array)($definition['allOf'] ?? []) as $branch) self::validateValue($branch, $value, $path);
        foreach (['anyOf','oneOf'] as $group) {
            if (empty($definition[$group])) continue;
            $matches=0;
            foreach ($definition[$group] as $branch) { try { self::validateValue($branch,$value,$path); $matches++; } catch (InvalidArgumentException $e) {} }
            if ($matches === 0 || ($group === 'oneOf' && $matches !== 1)) throw new InvalidArgumentException('参数组合不正确：' . $path);
        }
        $type = $definition['type'] ?? '';
        if (is_array($type)) $type = array_values(array_diff($type, ['null']))[0] ?? '';
        $valid = match ($type) {
            'string' => is_string($value), 'integer' => is_int($value), 'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value), 'array' => is_array($value) && array_is_list($value), 'object' => is_array($value) || is_object($value), default => true,
        };
        if (!$valid) throw new InvalidArgumentException('参数格式不正确：' . $path);
        $options = self::choices($definition);
        if ($options !== [] && !in_array($value, $options, true) && !in_array((string)(is_scalar($value) ? $value : ''), array_map('strval', $options), true)) throw new InvalidArgumentException('参数选项不支持：' . $path);
        if (is_numeric($value) && in_array($type, ['integer','number'], true)) {
            if ((isset($definition['minimum']) && $value < $definition['minimum']) || (isset($definition['maximum']) && $value > $definition['maximum'])) throw new InvalidArgumentException('参数超出范围：' . $path);
        }
        if (is_string($value)) {
            if ((isset($definition['minLength']) && mb_strlen($value) < $definition['minLength']) || (isset($definition['maxLength']) && mb_strlen($value) > $definition['maxLength'])) throw new InvalidArgumentException('参数长度不正确：' . $path);
            if (isset($definition['pattern']) && @preg_match('~' . str_replace('~','\\~',(string)$definition['pattern']) . '~u', $value) === 0) throw new InvalidArgumentException('参数格式不正确：' . $path);
        }
        if (is_array($value) && $type === 'array') {
            if ((isset($definition['minItems']) && count($value) < $definition['minItems']) || (isset($definition['maxItems']) && count($value) > $definition['maxItems'])) throw new InvalidArgumentException('素材或参数数量不正确：' . $path);
            foreach ($value as $index => $item) self::validateValue((array)($definition['items'] ?? []), $item, $path . '[' . $index . ']');
        }
        if (is_array($value) && $type === 'object') self::validate($definition, $value, '', $path . '.');
    }
}
