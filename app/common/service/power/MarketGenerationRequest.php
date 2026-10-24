<?php
namespace app\common\service\power;

use InvalidArgumentException;

/** Builds only declared business fields; credentials and task transport stay with the runtime. */
final class MarketGenerationRequest
{
    public static function metadata(array $snapshot): array
    {
        $metadata = MarketGenerationSchema::array($snapshot['market_metadata'] ?? []);
        if (!isset($metadata['params_schema'])) $metadata['params_schema'] = MarketGenerationSchema::array($snapshot['params_schema'] ?? []);
        return $metadata;
    }

    public static function payload(array $snapshot, array $request): array
    {
        $metadata = self::metadata($snapshot);
        $schema = MarketGenerationSchema::array($metadata['params_schema'] ?? []);
        $properties = MarketGenerationSchema::properties($schema);
        $locked = MarketGenerationSchema::array($snapshot['locked_params'] ?? []);
        $params = array_replace(MarketGenerationSchema::array($metadata['default_params'] ?? []), MarketGenerationSchema::array($request['provider_params'] ?? []));
        foreach ($properties as $field => $_) if (array_key_exists($field, $request) && !in_array($field, ['quality','resolution'], true)) $params[$field] = $request[$field];
        foreach (['prompt','content'] as $field) if (isset($properties[$field]) && isset($request['prompt'])) $params[$field] = $request['prompt'];
        $ratio = $request['ratio'] ?? $request['aspect_ratio'] ?? null;
        if ($ratio !== null && $ratio !== '') foreach (['aspect_ratio','ratio','image_size'] as $field) {
            if (!isset($properties[$field])) continue;
            if ($field === 'image_size' && !in_array($ratio, MarketGenerationSchema::choices($properties[$field]), true)) continue;
            $params[$field] = $ratio; break;
        }
        $resolution = $request['resolution'] ?? $request['quality'] ?? null;
        if ($resolution !== null && $resolution !== '') foreach (['resolution','image_size','quality','size'] as $field) {
            if (!isset($properties[$field])) continue;
            $choices = MarketGenerationSchema::choices($properties[$field]);
            if ($choices !== [] && !in_array(strtolower((string)$resolution), array_map(static fn($v)=>strtolower((string)$v),$choices), true)) continue;
            $params[$field] = $resolution; break;
        }
        foreach (['duration','seconds','video_duration'] as $field) if (isset($properties[$field]) && array_key_exists('duration', $request)) { $params[$field] = $request['duration']; break; }
        if (isset($properties['n'])) $params['n'] = max(1, (int)($request['quantity'] ?? $request['n'] ?? 1));
        $material = MarketGenerationMaterials::serialize($metadata, $request);
        $mode = MarketGenerationMaterials::select($metadata, $request);
        // Remove defaults from other material modes rather than accidentally submitting their example inputs.
        foreach (MarketGenerationMaterials::modes($metadata) as $other) foreach ($other['fields'] as $field) {
            if (!array_key_exists($field['field'], (array)($request['provider_params'] ?? []))) unset($params[$field['field']]);
        }
        $params = array_replace($params, $material);
        foreach ($locked as $key=>$value) {
            if (!isset($properties[$key]) || str_starts_with($key, '_')) continue;
            if ($key === 'generation_type' && isset($material[$key]) && $value !== $material[$key]) throw new InvalidArgumentException('当前 SKU 不支持所选生成方式');
            $params[$key] = $value;
        }
        $native = (string)($mode['native_mode'] ?? '');
        $params = MarketGenerationSchema::normalize($schema, $params, $native);
        MarketGenerationMaterials::validate($metadata, $request, $params);
        MarketGenerationSchema::validate($schema, $params, $native);
        return $params;
    }
}
