<?php
namespace app\common\service\power;

use InvalidArgumentException;

/** Numeric platform identity is transport metadata, never a capability grant. */
final class MarketGenerationSourceIdentity
{
    public static function modelId(array $model): int
    {
        foreach ([$model['ai_model_id'] ?? 0,$model['model_id'] ?? 0,
            $model['pricing']['pricing_v2']['resource_id'] ?? 0,$model['pricing_v2']['resource_id'] ?? 0] as $id) {
            if (is_numeric($id) && (int)$id>0) return (int)$id;
        }
        return 0;
    }

    public static function bind(array $model, array $resource): array
    {
        $id=self::modelId($model);$resourceId=(int)($resource['model_id'] ?? 0);
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
        return self::modelId($model)>0 && self::modelId($model)===(int)($pricing['model_id'] ?? 0)
            && $code!=='' && $code===(string)($pricing['model_code'] ?? '');
    }
}
