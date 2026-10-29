<?php
namespace app\common\service\power;

/** Pure synthetic recipes for reviewed structures. Never used by generation/admission. */
final class MarketGenerationAuditFixtures
{
    public static function cases(array $mode, array $base, array $rules): array
    {
        $schema = (array)($mode['parameter_schema'] ?? []);
        $properties = MarketGenerationSchema::properties($schema);
        if ($properties === []) return [];
        $params = []; $complex = [];
        foreach ((array)($schema['required'] ?? []) as $key) {
            if (in_array($key, ['prompt','content','duration','resolution','aspect_ratio'], true)) continue;
            $definition = $properties[$key] ?? [];
            if (array_key_exists('default', $definition)) $params[$key] = $definition['default'];
            elseif (($choices = MarketGenerationSchema::choices($definition)) !== []) $params[$key] = $choices[0];
            elseif (($definition['type'] ?? '') === 'boolean') $params[$key] = false;
        }
        foreach ((array)$mode['fields'] as $field) {
            $key = $field['field']; $definition = $properties[$key] ?? [];
            if (($field['maximum'] ?? 1) === 0) continue;
            $value = self::material($field, $definition);
            // Missing child schemas or unknown shapes remain explicitly untested.
            if ($value === null) return [];
            $params[$key] = $value;
            if (!in_array($field['kind'], ['image','video','audio'], true)
                || ($definition['items']['type'] ?? '') === 'object' || str_ends_with($key, '_ids')) $complex[] = $key;
        }
        if ($complex === []) return [];
        $all = $params;
        foreach ($rules as $rule) {
            if (($rule['kind'] ?? '') !== 'exclusive') continue;
            if (array_intersect(array_keys($params), $rule['left']) && array_intersect(array_keys($params), $rule['right'])) {
                foreach ($rule['right'] as $key) unset($params[$key]);
            }
        }
        $request = array_replace($base, ['provider_params'=>$params,'reference_assets'=>[],'reference_images'=>[]]);
        $cases = [self::row($mode, 'structured', $request, 'constructed')];
        if ($all !== $params) $cases[] = self::row($mode, 'exclusive_materials', array_replace($request, ['provider_params'=>$all]), 'rejected');
        foreach ($mode['fields'] as $field) {
            $key = $field['field'];
            if (!isset($params[$key])) continue;
            $bad = $params; $bad[$key] = 'wrong_nested_type';
            $cases[] = self::row($mode, 'wrong_type:'.$key, array_replace($request, ['provider_params'=>$bad]), 'rejected');
            $maximum = $field['maximum'] ?? $properties[$key]['maxItems'] ?? null;
            if (is_array($params[$key]) && array_is_list($params[$key]) && $maximum !== null && $maximum <= 16) {
                $bad = $params; $bad[$key] = array_fill(0, (int)$maximum + 1, $params[$key][0]);
                $cases[] = self::row($mode, 'over_limit:'.$key, array_replace($request, ['provider_params'=>$bad]), 'rejected');
            }
            $item = $properties[$key]['items'] ?? [];
            foreach ((array)($item['required'] ?? []) as $required) {
                $bad = $params; unset($bad[$key][0][$required]);
                $cases[] = self::row($mode, 'missing_child:'.$key.'.'.$required, array_replace($request, ['provider_params'=>$bad]), 'rejected');
            }
            if (($item['additionalProperties'] ?? true) === false) {
                $bad = $params; $bad[$key][0]['undeclared_child'] = 'must reject';
                $cases[] = self::row($mode, 'unknown_child:'.$key, array_replace($request, ['provider_params'=>$bad]), 'rejected');
            }
            if (isset($item['properties']['start'], $item['properties']['ends'])) {
                $bad = $params; $bad[$key][0]['ends'] = $bad[$key][0]['start'];
                $cases[] = self::row($mode, 'invalid_clip_range:'.$key, array_replace($request, ['provider_params'=>$bad]), 'rejected');
            }
        }
        return $cases;
    }

    private static function row(array $mode, string $name, array $request, string $expected): array
    {
        return ['case'=>$mode['key'].'#'.$name, 'mode_case'=>$mode['key'], 'request'=>$request, 'skip'=>false,
            'expected'=>$expected, 'fixture_scope'=>'synthetic declared structure; SKU compatibility and measured media remain separate'];
    }

    private static function material(array $field, array $schema)
    {
        $type = $schema['type'] ?? ''; $kind = $field['kind']; $key = $field['field'];
        if ($type === 'string') return self::url($key, $kind);
        if ($type !== 'array' || !isset($schema['items'])) return null;
        if (($schema['items']['type'] ?? '') === 'string') {
            $count = max(1, (int)($field['minimum'] ?? 1));
            if ($count > 16) return null;
            return array_map(static fn($i)=>str_ends_with($key, '_ids') ? 'offline_id_'.$i : self::url($key.$i, $kind), range(1,$count));
        }
        $properties = $schema['items']['properties'] ?? [];
        if (isset($properties['element_input_urls'], $properties['name'], $properties['description'])) {
            return [['name'=>'FixtureSubject','description'=>'Offline subject',
                'element_input_urls'=>[self::url($key.'1','image'),self::url($key.'2','image')]]];
        }
        if ($kind === 'subject' && isset($properties['name'], $properties['image_urls'])) {
            return [['name'=>'FixtureSubject','image_urls'=>[self::url($key.'1','image'),self::url($key.'2','image')]]];
        }
        if (isset($properties['name'], $properties['audio_url'])) return [['name'=>'FixtureVoice','audio_url'=>self::url($key,'audio')]];
        if (isset($properties['url'], $properties['start'], $properties['ends'])) return [['url'=>self::url($key,'video'),'start'=>0,'ends'=>1]];
        return null;
    }

    private static function url(string $key, string $kind): string
    {
        return 'https://fixtures.invalid/'.$key.'.'.match ($kind) { 'image','subject'=>'png','video'=>'mp4','audio'=>'mp3',default=>'pdf' };
    }
}
