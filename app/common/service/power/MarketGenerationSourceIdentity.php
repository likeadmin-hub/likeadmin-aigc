<?php
namespace app\common\service\power;

use InvalidArgumentException;

/** Numeric platform identity is transport metadata, never a capability grant. */
final class MarketGenerationSourceIdentity
{
    private static function positiveId($value): int
    {
        if (is_int($value)) return max(0,$value);
        if (!is_string($value) || !preg_match('/^[1-9][0-9]*$/D',$value)) return 0;
        return filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]) ?: 0;
    }

    public static function modelId(array $model): int
    {
        foreach ([$model['ai_model_id'] ?? 0,$model['model_id'] ?? 0,
            $model['pricing']['pricing_v2']['resource_id'] ?? 0,$model['pricing_v2']['resource_id'] ?? 0] as $id) {
            if (self::positiveId($id)>0) return self::positiveId($id);
        }
        return 0;
    }

    /** Validate before looking up or caching; keep modelId's legacy extraction contract unchanged. */
    public static function hasConsistentModelIdentity(array $model): bool
    {
        $id=self::modelId($model);
        if ($id<=0 || ($model['resource_type']??null)==='app_api'
            || ($model['pricing_v2']['resource_type']??null)==='app_api'
            || ($model['pricing']['pricing_v2']['resource_type']??null)==='app_api') return false;
        foreach ([$model['ai_model_id']??null,$model['model_id']??null,
            $model['pricing']['pricing_v2']['resource_id']??null,$model['pricing_v2']['resource_id']??null] as $candidate) {
            if (!in_array($candidate,[null,0,'0',''],true) && self::positiveId($candidate)!==$id) return false;
        }
        return true;
    }

    /** Only call with an upstream API row, never a local market product. */
    public static function appApiId(array $api): int
    {
        $id=0;
        foreach (['ai_app_api_id','api_id','id'] as $field) {
            if (!array_key_exists($field,$api) || $api[$field]===null) continue;
            $candidate=self::positiveId($api[$field]);
            if ($candidate===0 || ($id>0 && $candidate!==$id)) return 0;
            $id=$candidate;
        }
        return $id;
    }

    /** Missing legacy context is allowed; an explicit foreign parent is not. */
    public static function hasConflictingSavedAppApiContext(array $metadata,string $appCode,string $apiCode): bool
    {
        $app=$metadata['upstream_app_metadata'] ?? [];$api=$metadata['upstream_api_metadata'] ?? [];
        if (!is_array($app) || !is_array($api)) return true;
        if ((isset($app['code']) && $app['code']!==$appCode)
            || (isset($api['code']) && $api['code']!==$apiCode)
            || (array_key_exists('app_code',$api) && $api['app_code']!==$appCode)) return true;
        $parentId=self::positiveId($app['id']??null);$childAppId=self::positiveId($api['app_id']??null);
        return $parentId>0 && $childAppId>0 && $parentId!==$childAppId;
    }

    /** A saved API ID is usable only inside its exact saved application/API context. */
    public static function savedAppApiId(array $metadata,string $appCode,string $apiCode): int
    {
        $app=$metadata['upstream_app_metadata'] ?? [];$api=$metadata['upstream_api_metadata'] ?? [];
        if (!is_array($app) || !is_array($api) || $appCode==='' || $apiCode===''
            || ($app['code']??null)!==$appCode || ($api['code']??null)!==$apiCode) return 0;
        if (self::hasConflictingSavedAppApiContext($metadata,$appCode,$apiCode)) return 0;
        return self::appApiId($api);
    }

    public static function bind(array $model, array $resource): array
    {
        $id=self::modelId($model);$resourceId=self::positiveId($resource['model_id'] ?? 0);
        if ($id>0) {
            if ($resourceId>0 && $id!==$resourceId) throw new InvalidArgumentException('平台模型身份与商品快照不一致');
            return $model+['model_id'=>$id];
        }
        if ($resourceId<=0) return $model;
        // Missing/ambiguous identities cannot be guessed from a display name or slug.
        if (!array_key_exists('model_code',$model) || !array_key_exists('channel_code',$model)
            || ($model['model_code'] ?? '') !== ($resource['model_code'] ?? null)
            || $model['channel_code'] !== ($resource['channel_code'] ?? null)) return $model;
        $model['model_id']=$resourceId;
        return $model;
    }

    public static function metadata(array $product, array $metadata): array
    {
        if (($product['resource_type'] ?? 'model') !== 'model') return $metadata;
        $source=MarketGenerationSchema::array($product['source_payload'] ?? []);
        $resource=MarketGenerationSchema::array($source['resource'] ?? $source['raw']['resource'] ?? []);
        if ($resource === []) return $metadata;
        $metadata['upstream_metadata']=self::bind(MarketGenerationSchema::array($metadata['upstream_metadata'] ?? []),$resource);
        return $metadata;
    }

    public static function matchesDetail(array $model, array $detail): bool
    {
        $pricing=(array)($detail['model_pricing'] ?? []);
        $code=(string)($model['model_code'] ?? $model['code'] ?? '');
        $id=self::modelId($model);
        if (!self::hasConsistentModelIdentity($model) || ($pricing['resource_type']??null)==='app_api'
            || ($pricing['pricing_v2']['resource_type']??null)==='app_api'
            || $id<=0 || $id!==self::positiveId($pricing['model_id'] ?? 0)
            || $code==='' || $code!==(string)($pricing['model_code'] ?? '')
            || !empty($detail['api_pricing']) || (isset($pricing['bind_type']) && $pricing['bind_type']!=='model')) return false;
        if (array_key_exists('channel_code',$model) && array_key_exists('channel_code',$pricing)
            && $model['channel_code']!==$pricing['channel_code']) return false;
        $pricingV2=(array)($pricing['pricing_v2']??[]);
        if (array_key_exists('resource_id',$pricingV2) && self::positiveId($pricingV2['resource_id'])!==$id) return false;
        $doc=(array)($detail['doc'] ?? []);
        return empty($doc['ai_app_api_id']) && (!isset($doc['ai_model_id']) || self::positiveId($doc['ai_model_id'])===$id);
    }

    public static function matchesApplicationDetail(array $identity,array $detail): bool
    {
        $pricing=(array)($detail['api_pricing'] ?? []);
        $app=$identity['app_code'] ?? '';$api=$identity['api_code'] ?? '';
        if (!is_string($app)||$app===''||!is_string($api)||$api===''||($pricing['app_code']??null)!==$app
            || ($pricing['api_code']??$pricing['code']??null)!==$api
            || (isset($pricing['code'])&&$pricing['code']!==$api) || !empty($detail['model_pricing'])
            || ($pricing['bind_type']??null)==='model' || ($pricing['resource_type']??null)==='model'
            || ($pricing['pricing_v2']['resource_type']??null)==='model') return false;
        $returnedId=self::appApiId($pricing);
        foreach (['ai_app_api_id','api_id','id'] as $field) {
            if (isset($pricing[$field]) && $returnedId===0) return false;
        }
        if (array_key_exists('api_id',$identity)) {
            $expected=self::positiveId($identity['api_id']);
            if ($expected===0 || $expected!==$returnedId) return false;
        }
        $pricingV2=(array)($pricing['pricing_v2']??[]);
        if (array_key_exists('resource_id',$pricingV2)
            && ($returnedId===0 || self::positiveId($pricingV2['resource_id'])!==$returnedId)) return false;
        $doc=(array)($detail['doc'] ?? []);
        if (!empty($doc['ai_model_id']) || (isset($doc['ai_app_api_id'])
            && ($returnedId===0 || self::positiveId($doc['ai_app_api_id'])!==$returnedId))) return false;
        return true;
    }
}
