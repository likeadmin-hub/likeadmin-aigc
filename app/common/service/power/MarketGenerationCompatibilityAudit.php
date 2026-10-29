<?php
namespace app\common\service\power;

/** Developer diagnostics only. No enablement decisions, HTTP, quotes, tasks or point writes. */
final class MarketGenerationCompatibilityAudit
{
    public static function review(array $schema, ?array $captured): array
    {
        $properties = MarketGenerationSchema::properties($schema);
        $opaque = [];
        $walk = static function (array $definition, string $path) use (&$walk, &$opaque): void {
            $type = (array)($definition['type'] ?? []);
            if ((in_array('object', $type, true) && !isset($definition['properties']) && !is_array($definition['additionalProperties'] ?? null)
                    && !isset($definition['oneOf']) && !isset($definition['anyOf']))
                || (in_array('array', $type, true) && !is_array($definition['items'] ?? null))) $opaque[] = $path;
            foreach ((array)($definition['properties'] ?? []) as $key=>$child) if (is_array($child)) $walk($child, $path.'.'.$key);
            if (is_array($definition['items'] ?? null)) $walk($definition['items'], $path.'[]');
        };
        foreach ($properties as $key=>$definition) $walk($definition, $key);
        $result = ['baseline'=>'2026-10-18 captured declaration, not current upstream verification',
            'captured_sample_found'=>$captured !== null, 'top_level_fields_match'=>null, 'declaration_matches'=>null,
            'changed_fields'=>[], 'opaque_structures'=>$opaque, 'current_upstream_contract'=>'unverified'];
        if ($captured === null) return $result;
        $before = MarketGenerationSchema::properties($captured);
        $names = array_keys($properties); $oldNames = array_keys($before); sort($names); sort($oldNames);
        $result['top_level_fields_match'] = $names === $oldNames;
        $result['declaration_matches'] = self::canonical($schema) === self::canonical($captured);
        foreach (array_unique(array_merge($names, $oldNames)) as $key) {
            if (self::canonical($properties[$key] ?? null) !== self::canonical($before[$key] ?? null)) $result['changed_fields'][] = $key;
        }
        return $result;
    }

    private static function canonical($value)
    {
        if (!is_array($value)) return $value;
        // Examples are neither defaults nor constraints; never expose them in the report.
        unset($value['example'], $value['examples']);
        foreach ($value as &$child) $child = self::canonical($child); unset($child);
        if (!array_is_list($value)) ksort($value);
        return $value;
    }

    public static function scenarios(string $type, array $option, array $metadata = []): array
    {
        $keys = $type === 'image' ? ['text_to_image','reference_image']
            : ['text_to_video','image_to_video','start_end','last_frame','image_reference','video_edit','audio_reference','omni_reference'];
        $rows = [];
        foreach ($keys as $key) $rows[$key] = ['scenario'=>$key, 'declared_support'=>null, 'material_modes'=>[],
            'sku_combinations'=>'unverified', 'live_generation'=>'unverified'];
        foreach ((array)($option['material_modes'] ?? []) as $mode) {
            $key = $type === 'image' ? (empty($mode['fields']) ? 'text_to_image' : 'reference_image') : (string)($mode['method'] ?? '');
            if (!isset($rows[$key])) continue;
            $rows[$key]['declared_support'] = true;
            $rows[$key]['material_modes'][] = (string)($mode['key'] ?? '');
        }
        // Missing modes or a zero/absent catalogue limit do not prove lack of support.
        // Only an explicit capability denial may mark a scenario unsupported.
        $raw = MarketGenerationSchema::array($metadata['upstream_metadata'] ?? $metadata);
        if (($raw['supports_first_last_frame'] ?? null) === false && isset($rows['start_end']) && $rows['start_end']['material_modes'] === []) {
            $rows['start_end']['declared_support'] = false;
        }
        return array_values($rows);
    }

    public static function sku(string $type, string $resource, array $sku): array
    {
        $result = ['market_sku_id'=>(int)($sku['id'] ?? 0),
            'locked_parameter_names'=>array_keys(MarketGenerationSchema::array($sku['locked_params'] ?? [])),
            'selectable_parameter_names'=>array_keys(MarketGenerationSchema::array($sku['selectable_params'] ?? [])),
            'meter_status'=>'unverified', 'meter'=>null, 'scenario_pricing'=>'unverified'];
        try {
            if ($type === 'image' && $resource === 'app_api') {
                MarketGenerationMeter::assertLegacyPricing($sku);
                $result['meter_status'] = 'legacy_application_pricing';
                return $result;
            }
            if (MarketUsageSettlementService::isActualUsageSku($sku)) {
                $result['meter_status'] = 'requires_submission_token_budget';
                return $result;
            }
            $meter = MarketGenerationMeter::contract($sku);
            $result['meter'] = array_intersect_key($meter, array_flip(['source','basis','round_up','free_images','image_numerator','image_denominator']));
            $result['meter_status'] = $meter['basis'] === 'megapixel' ? 'requires_verified_pixels' : 'recognized';
            if ($type === 'image' && !in_array($meter['basis'], ['call','image','megapixel'], true)) $result['meter_status'] = 'unsupported_meter';
            if ($type === 'video' && in_array($meter['basis'], ['image','megapixel'], true)) $result['meter_status'] = 'unsupported_meter';
        } catch (\InvalidArgumentException) {
            $result['meter_status'] = 'unsupported_or_invalid_meter';
        }
        return $result;
    }

    /** One representative input per mode (or legacy scenario); never material/parameter examples. */
    public static function cases(string $type, array $option): array
    {
        $base = ['prompt'=>'Offline compatibility fixture', 'quantity'=>1];
        foreach (['ratio'=>'ratio_options','quality'=>'resolution_options','duration'=>'duration_options'] as $key=>$list) {
            foreach ((array)($option[$list] ?? []) as $candidate) {
                $value = is_array($candidate) ? ($candidate['value'] ?? null) : $candidate;
                if (!is_scalar($value) || $value === '' || ($key === 'duration' && (!is_numeric($value) || (float)$value <= 0))) continue;
                $base[$key] = $value; break;
            }
        }
        $modes = (array)($option['material_modes'] ?? []);
        if ($modes === []) {
            $modes = $type === 'image' ? [
                ['key'=>'legacy:text','fields'=>[]],
                ['key'=>'legacy:reference','fields'=>[['kind'=>'image','role'=>'reference']]],
            ] : [
                ['key'=>'legacy:text','method'=>'text_to_video','fields'=>[]],
                ['key'=>'legacy:first','method'=>'image_to_video','fields'=>[['kind'=>'image','role'=>'first']]],
                ['key'=>'legacy:pair','method'=>'start_end','fields'=>[['kind'=>'image','role'=>'first'],['kind'=>'image','role'=>'last']]],
                ['key'=>'legacy:reference','method'=>'image_reference','fields'=>[['kind'=>'image','role'=>'reference']]],
                ['key'=>'legacy:video','method'=>'video_edit','fields'=>[['kind'=>'video','role'=>'reference']]],
                ['key'=>'legacy:audio','method'=>'audio_reference','fields'=>[['kind'=>'audio','role'=>'reference']]],
            ];
        }
        $result = [];
        foreach ($modes as $mode) {
            $request = $base;
            if (isset($mode['method'])) $request['generation_method'] = $mode['method'];
            if (!str_starts_with((string)$mode['key'], 'legacy:')) $request['material_mode'] = $mode['key'];
            $nativeProperties = MarketGenerationSchema::properties((array)($mode['parameter_schema'] ?? []));
            if ($nativeProperties !== [] && !isset($nativeProperties['prompt']) && !isset($nativeProperties['content'])) unset($request['prompt']);
            $assets = []; $skip = false;
            foreach ((array)($mode['fields'] ?? []) as $field) {
                $kind = (string)($field['kind'] ?? ''); $count = max(1, (int)($field['minimum'] ?? 1));
                if (!in_array($kind, ['image','video','audio'], true) || $count > 16) { $skip = true; break; }
                if (isset($field['maximum']) && (int)$field['maximum'] === 0) continue;
                for ($i=0; $i<$count; $i++) {
                    $role = (string)($field['role'] ?? 'reference');
                    if ($role === 'frames') $role = $i === 0 ? 'first' : 'last';
                    $assets[] = ['type'=>$kind, 'role'=>match ($role) {
                        'first'=>'first_frame_image', 'last'=>'last_frame_image', default=>'reference_'.$kind,
                    }, 'url'=>'https://fixtures.invalid/audit-'.count($assets).'.'.(['image'=>'png','video'=>'mp4','audio'=>'mp3'][$kind])];
                }
            }
            $request['reference_assets'] = $assets;
            $request['reference_images'] = array_column(array_filter($assets, static fn($a)=>$a['type'] === 'image'), 'url');
            $result[] = ['case'=>(string)$mode['key'], 'request'=>$request, 'skip'=>$skip];
        }
        return $result;
    }

    /** Call only pure prepare/payload methods, with no billing meter or real material URLs. */
    public static function probe(array $case, callable $prepare): array
    {
        $result = ['case'=>$case['case'], 'scope'=>'one synthetic input; no quote, settlement or upstream validation'];
        if ($case['skip']) return $result + ['status'=>'needs_structured_fixture'];
        try {
            $native = $prepare($case['request']);
            $leaves = [];
            array_walk_recursive($native, static function ($value) use (&$leaves): void { if (is_string($value)) $leaves[] = $value; });
            $missing = array_diff(array_column($case['request']['reference_assets'], 'url'), $leaves);
            $status = $missing === [] ? 'constructed' : 'material_not_forwarded';
            if ($status === 'constructed' && isset($case['request']['prompt']) && !str_contains(implode(' ', $leaves), $case['request']['prompt'])) $status = 'prompt_not_forwarded';
            return $result + ['status'=>$status];
        } catch (\Throwable $e) {
            // Exceptions may contain a rejected input value: do not print raw messages.
            return $result + ['status'=>'rejected', 'error_type'=>get_class($e)];
        }
    }
}
