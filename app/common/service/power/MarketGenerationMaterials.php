<?php
namespace app\common\service\power;

use InvalidArgumentException;

/** Material fields are native contracts, not interchangeable URL aliases. */
final class MarketGenerationMaterials
{
    public static function contract(array $metadata): array
    {
        return MarketGenerationSchema::array($metadata['material_capabilities'] ?? $metadata['upstream_metadata']['material_capabilities'] ?? []);
    }

    public static function modes(array $metadata): array
    {
        $result = [];
        $declared = (array)(self::contract($metadata)['modes'] ?? []);
        $expanded = [];
        foreach ($declared as $mode) {
            if (!is_array($mode)) continue;
            $fields = array_values(array_filter((array)($mode['fields'] ?? []), static fn($f)=>is_array($f) && !str_contains((string)($f['field'] ?? ''), '.')));
            $frames = array_values(array_filter($fields, static fn($f)=>in_array($f['role'] ?? '', ['first','last'],true)));
            if (empty($mode['view_key']) && count($frames) === 2 && !array_filter($frames, static fn($f)=>!empty($f['required']))) {
                $base = array_values(array_filter($fields, static fn($f)=>!in_array($f['role'] ?? '', ['first','last'],true)));
                $expanded[] = array_replace($mode, ['fields'=>$base,'first_last_frame'=>'不支持','view_key'=>($mode['code'] ?? 'default').':reference']);
                foreach (['first'=>'image_to_video','last'=>'last_frame','pair'=>'start_end'] as $view=>$method) {
                    if ($view === 'pair' && ($mode['first_last_frame'] ?? '') !== '支持') continue;
                    $selected = $base;
                    foreach ($frames as $frame) if ($view === 'pair' || $frame['role'] === $view) $selected[] = array_replace($frame,['required'=>true,'minimum'=>1]);
                    $expanded[] = array_replace($mode,['fields'=>$selected,'method'=>$method,'view_key'=>($mode['code'] ?? 'default').':'.$view]);
                }
            } else $expanded[] = $mode;
        }
        foreach ($expanded as $index => $mode) {
            if (!is_array($mode)) continue;
            $fields = array_values(array_filter((array)($mode['fields'] ?? []), static fn($f) => is_array($f) && !str_contains((string)($f['field'] ?? ''), '.')));
            $roles = array_column($fields, 'role');
            $kinds = array_unique(array_column($fields, 'kind'));
            $code = (string)($mode['code'] ?? '');
            if (!empty($mode['method'])) $method = $mode['method'];
            elseif (in_array($code, ['text_to_video','text_2_video','text_to_image'], true) || str_ends_with((string)($mode['view_key'] ?? ''), ':text')) $method = 'text_to_video';
            elseif ($code === 'reference_to_video') $method = count($kinds) > 1 ? 'omni_reference' : (($kinds[0] ?? '') === 'video' ? 'video_edit' : 'image_reference');
            elseif (($mode['first_last_frame'] ?? '') === '支持' && (in_array('first', $roles, true) || in_array('frames', $roles, true))) $method = 'start_end';
            elseif (in_array('last', $roles, true) && !in_array('first', $roles, true)) $method = 'last_frame';
            elseif (in_array('first', $roles, true) || in_array('frames', $roles, true)) $method = 'image_to_video';
            elseif ($fields === [] || (!array_filter($fields,static fn($f)=>!empty($f['required'])) && !array_diff($roles,['keyframe']))) $method = 'text_to_video';
            elseif (count($kinds) > 1 || array_intersect($kinds, ['subject','mixed','character','file'])) $method = 'omni_reference';
            else $method = match ($kinds[0] ?? '') { 'video'=>'video_edit', 'audio'=>'audio_reference', default=>'image_reference' };
            $fingerprint = json_encode([$code, $method, $fields]);
            if (isset($result[$fingerprint])) continue;
            $result[$fingerprint] = array_merge($mode, ['key'=>(string)($mode['view_key'] ?? ($code ?: 'default') . ':' . $index), 'native_mode'=>$code, 'method'=>$method, 'fields'=>$fields]);
        }
        return array_values($result);
    }

    public static function limit(array $metadata, string $kind): int
    {
        $limit = 0;
        foreach (self::modes($metadata) as $mode) {
            $total = 0;
            foreach ($mode['fields'] as $field) if (($field['kind'] ?? '') === $kind) $total += max(0, (int)($field['maximum'] ?? $field['minimum'] ?? 0));
            $limit = max($limit, $total);
        }
        return $limit;
    }

    public static function select(array $metadata, array $request): ?array
    {
        $modes = self::modes($metadata);
        if ($modes === []) return null;
        $method = (string)($request['generation_method'] ?? '');
        $key = (string)($request['material_mode'] ?? $request['provider_params']['material_mode'] ?? '');
        $native = (string)($request['generation_type'] ?? $request['provider_params']['generation_type'] ?? '');
        $candidates = array_values(array_filter($modes, static fn($mode) => ($key === '' || $key === $mode['key']) && ($method === '' || $method === $mode['method']) && ($native === '' || $native === $mode['native_mode'])));
        if ($candidates === []) throw new InvalidArgumentException('当前模型不支持所选生成方式');
        $assets = self::assets($request);
        $values = MarketGenerationSchema::array($request['provider_params'] ?? []);
        $best = null; $score = -PHP_INT_MAX;
        foreach ($candidates as $mode) {
            $current = 0;
            foreach ($mode['fields'] as $field) {
                $matched = self::matching($assets, $field, $mode['method']);
                if (!empty($values[$field['field']])) $current += 8;
                elseif ($matched !== []) $current += 4;
                elseif (!empty($field['required'])) $current -= 20;
            }
            if ($assets === [] && $values === [] && $mode['fields'] === []) $current += 20;
            if ($current > $score) { $score = $current; $best = $mode; }
        }
        return $best;
    }

    public static function assets(array $request): array
    {
        $assets = [];
        foreach ((array)($request['reference_assets'] ?? []) as $asset) {
            if (!is_array($asset)) continue;
            $url = (string)($asset['url'] ?? $asset['uri'] ?? '');
            if ($url === '') continue;
            $assets[] = $asset + ['url'=>$url, 'type'=>'image', 'role'=>'reference_image'];
        }
        foreach ($assets === [] ? (array)($request['reference_images'] ?? []) : [] as $url) {
            if (is_string($url) && $url !== '') $assets[] = ['url'=>$url,'type'=>'image','role'=>'reference_image'];
        }
        return $assets;
    }

    private static function matching(array $assets, array $field, string $method): array
    {
        $role = (string)($field['role'] ?? 'reference');
        $kind = (string)($field['kind'] ?? 'image');
        $name = (string)($field['field'] ?? '');
        $images = array_values(array_filter($assets, static fn($a) => ($a['type'] ?? 'image') === 'image'));
        return array_values(array_filter($assets, static function ($asset) use ($role,$kind,$name,$images,$method): bool {
            if (isset($asset['field']) && $asset['field'] !== '') return $asset['field'] === $name;
            if (($asset['type'] ?? 'image') !== $kind) return false;
            $assetRole = (string)($asset['role'] ?? 'reference_' . $kind);
            if ($role === 'first') return $assetRole === 'first_frame_image' || ($method === 'image_to_video' && count($images) === 1 && $assetRole === 'reference_image');
            if ($role === 'last') return $assetRole === 'last_frame_image' || ($method === 'last_frame' && count($images) === 1 && $assetRole === 'reference_image');
            if ($role === 'frames') return in_array($assetRole, ['first_frame_image','last_frame_image','reference_image'], true);
            if ($role === 'reference') return $assetRole === 'reference_' . $kind;
            return $assetRole === $role || $assetRole === $role . '_' . $kind;
        }));
    }

    public static function serialize(array $metadata, array $request): array
    {
        $mode = self::select($metadata, $request);
        if ($mode === null) return [];
        $schema = MarketGenerationSchema::properties(MarketGenerationSchema::array($metadata['params_schema'] ?? []));
        $params = MarketGenerationSchema::array($request['provider_params'] ?? []);
        $assets = self::assets($request);
        $result = [];
        foreach ($mode['fields'] as $field) {
            $name = $field['field'];
            if (array_key_exists($name, $params) && $params[$name] !== '' && $params[$name] !== []) { $result[$name] = $params[$name]; continue; }
            $matched = self::matching($assets, $field, $mode['method']);
            if ($matched === []) continue;
            if (empty($field['required']) && count($matched) > (int)($field['maximum'] ?? PHP_INT_MAX) && ($schema[$name]['type'] ?? '') !== 'array') continue;
            $definition = $schema[$name] ?? [];
            $urls = array_map(static fn($asset) => $asset['url'], $matched);
            if (($definition['type'] ?? '') === 'array') {
                $items = (array)($definition['items'] ?? []);
                if (($items['type'] ?? '') === 'object') {
                    $properties = MarketGenerationSchema::properties($items);
                    $urlKey = null;
                    foreach ($properties as $key => $_) if (preg_match('/(?:url|uri)$/i', $key)) { $urlKey = $key; break; }
                    if ($urlKey === null) continue; // Subjects/IDs need their own structured values.
                    $result[$name] = array_map(static function ($asset) use ($items,$urlKey): array {
                        $value = [$urlKey=>$asset['url']];
                        foreach ((array)($asset['parameters'] ?? []) as $key=>$item) if ($key !== $urlKey) $value[$key]=$item;
                        return MarketGenerationSchema::normalize($items, $value);
                    }, $matched);
                } else $result[$name] = $urls;
            } else {
                if (count($urls) > 1) throw new InvalidArgumentException('该素材输入只允许一个文件：' . $name);
                $result[$name] = $urls[0];
            }
        }
        if ($mode['native_mode'] !== '' && isset($schema['generation_type'])) $result['generation_type'] = $mode['native_mode'];
        return $result;
    }

    public static function validate(array $metadata, array $request, array $payload): void
    {
        $mode = self::select($metadata, $request);
        if ($mode === null) return;
        foreach ($mode['fields'] as $field) {
            $value = $payload[$field['field']] ?? null;
            $count = is_array($value) ? count($value) : (($value !== null && $value !== '') ? 1 : 0);
            $minimum = !empty($field['required']) ? max(1, (int)($field['minimum'] ?? 0)) : 0;
            $maximum = $field['maximum'] ?? null;
            if ($count < $minimum || ($maximum !== null && $count > (int)$maximum)) throw new InvalidArgumentException('素材数量不符合当前方式要求：' . $field['field']);
        }
        $declared = array_column($mode['fields'], 'field');
        foreach (self::modes($metadata) as $other) foreach ($other['fields'] as $field) {
            if (!in_array($field['field'], $declared, true) && !empty($payload[$field['field']])) throw new InvalidArgumentException('当前生成方式不支持此素材：' . $field['field']);
        }
        foreach (self::assets($request) as $asset) {
            if (!array_filter($mode['fields'], static fn($field) => self::matching([$asset], $field, $mode['method']) !== [])) throw new InvalidArgumentException('当前生成方式不支持已添加的素材角色');
        }
    }
}
