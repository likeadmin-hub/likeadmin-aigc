<?php
namespace app\common\service\power;

/** The task API owns routing; model drivers receive only native business input. */
final class MarketModelTaskRequest
{
    public static function payload(array $payload): array
    {
        $business = is_array($payload['params'] ?? null) ? $payload['params'] : $payload;
        foreach (['model','channel','callback_url','storage','params','market_product_id','market_sku_id','sku_id','market_sku_key','pricing_sku_key','price_source','idempotency_key'] as $key) unset($business[$key]);
        // sku_key is an upstream commercial selector. Local numeric SKU IDs and
        // local deduplication keys are not part of the provider's model schema.
        return array_intersect_key($payload,array_flip(['model','channel','callback_url','storage'])) + ['params'=>$business];
    }
}
