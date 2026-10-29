<?php
namespace app\common\service\power;

use InvalidArgumentException;

/** Trusted local contracts. Raw market declarations remain unchanged; task snapshots carry their selected revision. */
final class MarketGenerationReviewedContract
{
    public const PIN = '_reviewed_generation_contract';

    public static function digest(array $value): string
    {
        $sort=static function($v)use(&$sort){if(!is_array($v))return $v;foreach($v as &$child)$child=$sort($child);unset($child);if(!array_is_list($v))ksort($v);return $v;};
        return hash('sha256',json_encode($sort($value),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    }

    public static function declarationFingerprint(array $metadata): string
    {
        // Deliberately bypass Materials::contract: that reader may expose the reviewed supplement.
        return self::digest([$metadata['params_schema'] ?? [],MarketGenerationSchema::array(
            $metadata['material_capabilities'] ?? $metadata['upstream_metadata']['material_capabilities'] ?? [])]);
    }

    public static function contractDigest(array $contract): string
    {
        unset($contract['contract_digest']);
        return self::digest($contract);
    }

    /** Called only with persisted products, never with browser business parameters. */
    public static function attachProduct(array $product,array $metadata,string $type): array
    {
        if (($product['resource_type'] ?? 'model')!=='model') return $metadata;
        if (!isset($product['upstream_model_code'])) return $metadata; // Historical frozen snapshots do not adopt new contracts.
        unset($metadata[self::PIN]);
        $path=getenv('MARKET_REVIEWED_GENERATION_CONTRACTS_PATH') ?: dirname(__DIR__,4).'/resources/market/reviewed-generation-contracts.json';
        if (!is_file($path)) return $metadata;
        $catalog=MarketCatalogReadScope::derive('reviewed_contract_catalogue',['path'=>$path],
            static fn()=>MarketGenerationSchema::array(file_get_contents($path)));
        if (($catalog['version'] ?? null)!==2 || !is_array($catalog['contracts'] ?? null)) throw new InvalidArgumentException('本地审核合同目录格式不正确');
        $matches=array_values(array_filter($catalog['contracts'],static fn($c)=>is_array($c)
            && ($c['generation_type'] ?? '')===$type && ($c['model_code'] ?? '')===$product['upstream_model_code']
            && ($c['channel_code'] ?? '')===($product['upstream_channel_code'] ?? null)));
        if ($matches===[]) return $metadata;
        if (count($matches)!==1) { $metadata[self::PIN]=['error'=>'ambiguous_identity'];return $metadata; }
        $contract=$matches[0];
        if (($metadata['upstream_metadata']['model_code'] ?? '')!==($contract['public_model_code'] ?? null)
            || (isset($metadata['upstream_metadata']['channel_code']) && $metadata['upstream_metadata']['channel_code']!==$contract['channel_code'])
            || !hash_equals((string)($contract['declaration_fingerprint'] ?? ''),self::declarationFingerprint($metadata))) {
            $metadata[self::PIN]=['error'=>'declaration_mismatch'];return $metadata;
        }
        self::assertRecord($contract);
        $metadata[self::PIN]=$contract;
        return $metadata;
    }

    public static function selected(array $metadata): ?array
    {
        if (!array_key_exists(self::PIN,$metadata)) return null;
        $contract=$metadata[self::PIN];
        if (!is_array($contract) || isset($contract['error'])) throw new InvalidArgumentException('当前模型的审核合同身份或声明已变化，请核对合同');
        self::assertRecord($contract);
        if (!hash_equals($contract['declaration_fingerprint'],self::declarationFingerprint($metadata))
            || ($metadata['upstream_metadata']['model_code'] ?? '')!==$contract['public_model_code']
            || (isset($metadata['upstream_metadata']['channel_code']) && $metadata['upstream_metadata']['channel_code']!==$contract['channel_code'])) {
            throw new InvalidArgumentException('冻结请求的原始声明与审核合同不一致');
        }
        return $contract;
    }

    public static function assertSnapshot(array $snapshot,string $type): ?array
    {
        $contract=self::selected(MarketGenerationRequest::metadata($snapshot));
        if ($contract===null) return null;
        if (($snapshot['resource_type'] ?? 'model')!=='model' || $contract['generation_type']!==$type
            || $contract['model_code']!==($snapshot['model_code'] ?? '') || $contract['channel_code']!==($snapshot['channel_code'] ?? '')) {
            throw new InvalidArgumentException('冻结请求与审核合同的型号或渠道不一致');
        }
        return $contract;
    }

    public static function assertRecord(array $contract): void
    {
        foreach (['generation_type','model_code','channel_code','public_model_code','revision','generation_protocol',
            'declaration_fingerprint','contract_digest'] as $key) {
            if (!is_string($contract[$key] ?? null) || $contract[$key]==='') throw new InvalidArgumentException('审核合同身份或版本不完整');
        }
        if (!in_array($contract['generation_type'],['image','video'],true) || empty($contract['modes'])
            || empty($contract['material_capabilities']['modes']) || empty($contract['source']['artifact_sha256'])
            || !preg_match('/^[a-f0-9]{64}$/D',$contract['declaration_fingerprint'])
            || !hash_equals($contract['contract_digest'],self::contractDigest($contract))) {
            throw new InvalidArgumentException('审核合同内容或指纹不完整');
        }
    }

    /** Validate the encoded business body without changing its supplier protocol or transport envelope. */
    public static function validatePayload(array $snapshot,array $request,array $payload,bool $deferMissingPrompt=false): void
    {
        $metadata=MarketGenerationRequest::metadata($snapshot);$contract=self::selected($metadata);
        if ($contract===null) return;
        $mode=MarketGenerationMaterials::select($metadata,$request);
        $key=(string)($mode['native_mode'] ?? '');
        $schema=MarketGenerationNativeContract::schema($metadata,$key);
        foreach ((array)($request['provider_params'] ?? []) as $name=>$_) {
            if (!array_key_exists($name,MarketGenerationSchema::properties($schema)) && !in_array($name,['material_mode','generation_type'],true)) throw new InvalidArgumentException('当前审核合同不支持此参数');
        }
        $values=array_intersect_key($payload,MarketGenerationSchema::properties($schema));
        $provider=(array)($request['provider_params'] ?? []);
        $defer=$deferMissingPrompt && !array_key_exists('prompt',$request) && !array_key_exists('content',$request)
            && !array_key_exists('prompt',$provider) && !array_key_exists('content',$provider) ? ['prompt','content'] : [];
        MarketGenerationSchema::validate($schema,$values,'','',$defer);
        MarketGenerationMaterials::validate($metadata,$request,$values);
        $locked=MarketGenerationSchema::array($snapshot['locked_params'] ?? []);
        if (isset($locked['generation_type']) && $locked['generation_type']!==$key) throw new InvalidArgumentException('当前 SKU 不支持所选生成方式');
        MarketGenerationRules::validate($contract['modes'][$key]['rules'] ?? [],$values);
    }
}
