<?php
namespace app\common\service\power;

use InvalidArgumentException;

/** Offline evidence and candidate import only. Never grants runtime capability. */
final class MarketGenerationContractReview
{
    /** Inspect the actual platform response, independently of the local native snapshot file. */
    public static function platformData(array $metadata, array $detail): array
    {
        $pricing=(array)($detail['model_pricing'] ?? []);
        $schema=is_array($pricing['params_schema'] ?? null) ? $pricing['params_schema'] : [];
        $material=MarketGenerationMaterials::contract($pricing);
        $upstream=(array)($metadata['upstream_metadata'] ?? []);
        $contractFields=['params_schema','material_capabilities','generation_contract','native_contract','request_schema','validation_rules'];
        return [
            'platform_detail_available'=>$pricing !== [],
            'platform_contract_fields'=>array_values(array_intersect($contractFields,array_keys($pricing))),
            'saved_upstream_contract_fields'=>array_values(array_intersect($contractFields,array_keys($upstream))),
            'platform_schema_opaque_paths'=>$schema === [] ? null : self::incomplete($schema),
            'saved_schema_opaque_paths'=>self::incomplete((array)($metadata['params_schema'] ?? [])),
            'platform_schema_matches_saved'=>$schema === [] ? null : MarketGenerationCompatibilityAudit::fingerprint($schema)
                === MarketGenerationCompatibilityAudit::fingerprint((array)($metadata['params_schema'] ?? [])),
            'platform_material_mode_count'=>count((array)($material['modes'] ?? [])),
            'saved_material_mode_count'=>count((array)(MarketGenerationMaterials::contract($metadata)['modes'] ?? [])),
            'published_document_available'=>trim((string)($detail['doc']['content'] ?? '')) !== '',
            'runtime_readiness'=>'unverified','sku_pricing'=>'unverified','live_generation'=>'unverified',
        ];
    }

    public static function catalogueData(array $metadata, array $declaration): array
    {
        $result=self::platformData($metadata,$declaration === [] ? [] : ['model_pricing'=>$declaration]);
        $result['catalogue_declaration_available']=$result['platform_detail_available'];
        unset($result['platform_detail_available'],$result['published_document_available']);
        $result['source_projection']='model_catalogue_detail';
        return $result;
    }

    public static function ledger(array $captured, array $observations, array $guidance = [], array $platformFlow = [], array $catalogueFlow = []): array
    {
        $rows = [];
        foreach ($observations['entries'] as $entry) {
            if ($entry['resource_type'] !== 'model') continue;
            $matches = array_values(array_filter($captured['contracts'], static fn($c)=>
                ($c['resource_type'] ?? '') === 'model' && ($c['type'] ?? '') === $entry['generation_type']
                && ($c['model_code'] ?? '') === $entry['model_code'] && ($c['channel_code'] ?? '') === $entry['channel_code']));
            if (count($matches) !== 1) throw new InvalidArgumentException('合同证据身份缺失或重复');
            $metadata = $matches[0]; $schema = $metadata['params_schema'];
            $documents = array_values(array_filter((array)($guidance['entries'] ?? []), static fn($d)=>
                ($d['generation_type'] ?? '') === $entry['generation_type'] && ($d['model_code'] ?? '') === $entry['model_code']
                && ($d['channel_code'] ?? '') === $entry['channel_code']));
            $platform=array_values(array_filter((array)($platformFlow['models'] ?? []),static fn($m)=>
                ($m['generation_type'] ?? '') === $entry['generation_type'] && ($m['model_code'] ?? '') === $entry['model_code']
                && ($m['channel_code'] ?? '') === $entry['channel_code']));
            $catalogue=array_values(array_filter((array)($catalogueFlow['models'] ?? []),static fn($m)=>
                ($m['generation_type'] ?? '') === $entry['generation_type'] && ($m['model_code'] ?? '') === $entry['model_code']
                && ($m['channel_code'] ?? '') === $entry['channel_code']));
            $catalogueBound=count($catalogue)===1 && ($catalogue[0]['platform_identity_verified'] ?? false)===true
                && ($catalogue[0]['read_state'] ?? '')==='read';
            $rows[] = array_intersect_key($entry,array_flip(['generation_type','model_code','channel_code'])) + [
                'protocol_family'=>MarketGenerationAdapterRegistry::modelProtocol($entry['generation_type'], ['model_code'=>$entry['model_code'],'market_metadata'=>$metadata]),
                'evidence_status'=>$catalogueBound ? 'platform_catalogue_reviewed'
                    : (!empty($platform[0]['platform_identity_verified']) ? 'platform_detail_reviewed'
                        : ($documents === [] ? 'public_declaration_only' : 'published_guidance_reviewed')),
                'source'=>$observations['model_source'], 'observed_at'=>$observations['model_catalogue_observed_at'],
                'parameter_fingerprint'=>MarketGenerationCompatibilityAudit::fingerprint($schema),
                'recorded_observation_matches'=>MarketGenerationCompatibilityAudit::fingerprint($schema) === $entry['current_parameter_fingerprint'],
                'opaque_fields'=>self::incomplete($schema),
                'published_guidance'=>$documents,
                'guidance_observed_at'=>$documents === [] ? null : ($guidance['observed_at'] ?? null),
                'platform_detail_observation'=>$platform[0] ?? null,
                'platform_observed_at'=>$platform === [] ? null : ($platformFlow['observed_at'] ?? null),
                'platform_catalogue_observation'=>$catalogueBound ? $catalogue[0] : null,
                'catalogue_observed_at'=>$catalogueBound ? ($catalogueFlow['observed_at'] ?? null) : null,
                'missing_evidence'=>['authoritative_admission_schema','material_modes_and_conditions','sku_binding_and_pricing'],
                'native_contract_importable'=>false, 'live_generation'=>'unverified',
            ];
        }
        return ['version'=>1,'scope'=>'Evidence ledger only; no native contract or capability is inferred','entries'=>$rows,
            'application_guidance'=>(array)($guidance['application_entries'] ?? [])];
    }

    /** Detect unknown child structures, including composition branches, without copying examples. */
    public static function incomplete(array $schema, string $path = ''): array
    {
        $missing = [];
        $walk = static function (array $d, string $p) use (&$walk,&$missing): void {
            if (isset($d['$ref'])) $missing[] = $p.'/$ref';
            if (!isset($d['type']) && !isset($d['properties']) && !isset($d['oneOf']) && !isset($d['anyOf'])
                && !isset($d['allOf']) && !isset($d['$ref']) && !isset($d['enum']) && !array_key_exists('const',$d)) $missing[]=$p;
            $types = (array)($d['type'] ?? []);
            if (in_array('array',$types,true) && (!isset($d['items']) || !is_array($d['items']) || $d['items'] === [])) $missing[] = $p.'[]';
            if (in_array('object',$types,true) && !isset($d['properties']) && !isset($d['oneOf'])
                && !isset($d['anyOf']) && !isset($d['allOf'])) $missing[] = $p;
            foreach ((array)($d['properties'] ?? []) as $k=>$child) if (is_array($child)) $walk($child,$p === '' ? $k : $p.'.'.$k);
            if (is_array($d['items'] ?? null)) $walk($d['items'],$p.'[]');
            foreach (['oneOf','anyOf','allOf'] as $group) foreach ((array)($d[$group] ?? []) as $i=>$branch) if (is_array($branch)) $walk($branch,$p.'/'.$group.'/'.$i);
        };
        // Public market declarations also use a flat property map.
        if (!isset($schema['type']) && !isset($schema['properties']) && !isset($schema['oneOf'])
            && !isset($schema['anyOf']) && !isset($schema['allOf'])) $schema=['type'=>'object','properties'=>MarketGenerationSchema::properties($schema)];
        $walk($schema,$path);
        return array_values(array_unique($missing));
    }

    /** Produce review candidates, never the active generation-contracts catalogue. */
    public static function candidates(array $bundle, string $sourceArtifact): array
    {
        if (($bundle['version'] ?? null) !== 1) throw new InvalidArgumentException('合同导入版本不支持');
        $source = array_intersect_key((array)($bundle['source'] ?? []),array_flip(['kind','reference','revision','reviewed_at','artifact_sha256']));
        if (!in_array($source['kind'] ?? '', ['gateway_validator','versioned_official_schema'],true)
            || !is_string($source['reference'] ?? null) || trim($source['reference']) === ''
            || !is_string($source['revision'] ?? null) || trim($source['revision']) === ''
            || !is_string($source['reviewed_at'] ?? null) || strtotime($source['reviewed_at']) === false
            || !hash_equals(hash('sha256',$sourceArtifact),(string)($source['artifact_sha256'] ?? ''))) throw new InvalidArgumentException('缺少已复核合同来源或原始文件指纹不符');
        if (preg_match('~^[a-z][a-z0-9+.-]*://~i',$source['reference'])) {
            $url=parse_url($source['reference']);
            if (!$url || !in_array($url['scheme'] ?? '',['https','http'],true) || isset($url['user']) || isset($url['pass'])
                || isset($url['query']) || isset($url['fragment'])) throw new InvalidArgumentException('合同来源不能包含认证信息');
        }
        $candidates = []; $identities = [];
        foreach ((array)($bundle['entries'] ?? []) as $entry) {
            foreach (['generation_type','model_code','channel_code','public_model_code','generation_protocol'] as $key) {
                if (!is_string($entry[$key] ?? null) || $entry[$key] === '') throw new InvalidArgumentException('合同身份不完整');
            }
            $identity = json_encode([$entry['generation_type'],$entry['model_code'],$entry['channel_code']]);
            if (isset($identities[$identity])) throw new InvalidArgumentException('合同型号和渠道重复');
            $identities[$identity] = true;
            $declaration = $entry['declaration'] ?? [];
            if (!is_array($declaration['params_schema'] ?? null) || !is_array($declaration['material_capabilities'] ?? null)
                || empty($declaration['material_capabilities']['modes'])) throw new InvalidArgumentException('缺少参数和素材声明');
            $metadata = ['params_schema'=>$declaration['params_schema'],'generation_protocol'=>$entry['generation_protocol'],
                'upstream_metadata'=>['model_code'=>$entry['public_model_code'],'material_capabilities'=>$declaration['material_capabilities']]];
            MarketGenerationAdapterRegistry::modelProtocol($entry['generation_type'], ['market_metadata'=>$metadata]);
            if (self::incomplete($declaration['params_schema']) !== []) throw new InvalidArgumentException('公开参数声明仍有未定义子结构');
            if (empty($entry['modes']) || !is_array($entry['modes'])) throw new InvalidArgumentException('缺少模式合同');
            $modeKeys = array_map(static fn($m)=>(string)($m['code'] ?? ''),$declaration['material_capabilities']['modes']);
            foreach (array_unique($modeKeys) as $code) if (!array_key_exists($code,$entry['modes'])) throw new InvalidArgumentException('模式合同未覆盖素材声明');
            foreach ($declaration['material_capabilities']['modes'] as $materialMode) {
                foreach ((array)($materialMode['fields'] ?? []) as $field) {
                    $name=(string)($field['field'] ?? '');
                    if ($name === '' || (!str_contains($name,'.') && !isset($entry['modes'][$materialMode['code'] ?? '']['schema']['properties'][$name]))) throw new InvalidArgumentException('素材字段缺少模式参数定义');
                }
            }
            foreach ($entry['modes'] as $mode) {
                if (!is_array($mode['schema'] ?? null) || ($mode['schema']['type'] ?? '') !== 'object'
                    || !isset($mode['schema']['properties']) || self::incomplete($mode['schema']) !== []) throw new InvalidArgumentException('模式参数合同不完整');
                self::assertSupportedSchema($mode['schema']);
                foreach ((array)($mode['rules'] ?? []) as $rule) {
                    if (!in_array($rule['kind'] ?? '',['requires','exclusive','exactly_one','at_least_one','max','range','equals','forbid_value','sum','clip_range','loop_frame','keyframes','kling3_shots','kling4','oct24_urls','subjects','subject_quota'],true)) throw new InvalidArgumentException('模式规则尚未实现');
                }
            }
            $sort = static function ($v) use (&$sort) { if (!is_array($v)) return $v;foreach ($v as &$child) $child=$sort($child);unset($child);if(!array_is_list($v))ksort($v);return $v; };
            $candidates[] = array_intersect_key($entry,array_flip(['generation_type','model_code','channel_code','public_model_code','generation_protocol'])) + [
                'fingerprint'=>hash('sha256',json_encode($sort([$declaration['params_schema'],$declaration['material_capabilities']]),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)),
                'modes'=>$entry['modes'],'source'=>$source,'sku_pricing'=>'unverified','measured_media'=>'unverified','activation'=>'review_candidate_only',
            ];
        }
        if ($candidates === []) throw new InvalidArgumentException('合同导入内容为空');
        return ['version'=>1,'scope'=>'Review candidates only; source authenticity and live behavior require independent verification','candidates'=>$candidates];
    }

    private static function assertSupportedSchema(array $schema): void
    {
        $allowed=['type','properties','items','required','minimum','maximum','minItems','maxItems','minLength','maxLength',
            'enum','const','pattern','additionalProperties','allOf','oneOf','anyOf','default','format','description','title','examples','example'];
        if (array_diff(array_keys($schema),$allowed)) throw new InvalidArgumentException('合同包含尚未支持的校验关键字');
        $types=(array)($schema['type'] ?? []);
        if (array_diff($types,['object','array','string','integer','number','boolean','null']) || count(array_diff($types,['null']))>1
            || is_array($schema['additionalProperties'] ?? null)) throw new InvalidArgumentException('合同包含尚未支持的参数类型');
        foreach ((array)($schema['properties'] ?? []) as $child) self::assertSupportedSchema($child);
        if (isset($schema['items'])) self::assertSupportedSchema($schema['items']);
        foreach (['oneOf','anyOf','allOf'] as $group) foreach ((array)($schema[$group] ?? []) as $branch) self::assertSupportedSchema($branch);
    }
}
