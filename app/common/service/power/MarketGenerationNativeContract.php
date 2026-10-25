<?php
namespace app\common\service\power;

/** Versioned adapter contracts generated from the authoritative upstream validators.
 * Match the complete synchronized declaration before using an adapter: a changed contract never silently reuses an older profile.
 */
final class MarketGenerationNativeContract
{
    private static ?array $catalog = null;
    private static function canonical($value)
    {
        if (!is_array($value)) return $value;
        foreach ($value as &$child) $child = self::canonical($child); unset($child);
        if (!array_is_list($value)) ksort($value);
        return $value;
    }
    public static function modes(array $metadata): array
    {
        if (self::$catalog === null) self::$catalog = MarketGenerationSchema::array(@file_get_contents(getenv('MARKET_GENERATION_CONTRACTS_PATH') ?: dirname(__DIR__,4).'/resources/market/generation-contracts.json'));
        $public = (string)($metadata['upstream_metadata']['model_code'] ?? '');
        $contract = self::$catalog['models'][$public] ?? null;
        if (!$contract) return [];
        $hash = hash('sha256', json_encode(self::canonical([$metadata['params_schema'] ?? [], MarketGenerationMaterials::contract($metadata)]), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        return hash_equals($contract['fingerprint'], $hash) ? $contract['modes'] : [];
    }
    public static function schema(array $metadata, string $mode): array
    {
        $modes=self::modes($metadata); $schema=$modes[$mode]['schema'] ?? $modes['']['schema'] ?? [];
        if (isset($schema['properties']['model'])) {
            $schema['properties']['model_version']=$schema['properties']['model'];unset($schema['properties']['model']);
            $schema['required']=array_map(static fn($field)=>$field==='model'?'model_version':$field,(array)($schema['required'] ?? []));
        }
        $public=MarketGenerationSchema::properties(MarketGenerationSchema::array($metadata['params_schema'] ?? []));
        foreach ((array)($schema['properties'] ?? []) as $key=>&$definition) {
            if (!is_array($definition) || !isset($public[$key])) continue;
            if ($key==='duration') {
                $values=MarketGenerationSchema::durationChoices(array_replace($public[$key],$definition));
                if (isset($definition['minimum']) && $definition['minimum']>0) $values=array_values(array_filter($values,static fn($value)=>$value>=$definition['minimum']));
                if ($values!==[]) $definition['enum']=array_map(static fn($value)=>($definition['type'] ?? '')==='string'?(string)$value:$value,$values);
            } elseif (!isset($definition['enum'])) {
                $values=MarketGenerationSchema::choices($public[$key]);
                if ($values!==[] && in_array($definition['type'] ?? '',['string','integer','number'],true)) $definition['enum']=$values;
            }
            $schema['properties'][$key]=$definition;
        } unset($definition);
        return $schema;
    }
    public static function rules(array $metadata, string $mode): array
    {
        $modes=self::modes($metadata); return $modes[$mode]['rules'] ?? $modes['']['rules'] ?? [];
    }
}
