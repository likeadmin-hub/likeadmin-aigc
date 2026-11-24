<?php
declare(strict_types=1);

namespace app\common\service\power;

use app\common\model\power\PowerMarketProduct;

final class MarketLayerDecomposition
{
    public const APP = 'aigc_layer_decomposition';
    public const MODEL = 'seedream-5-5-layer-decomposition';
    public const STYLE = 'layer_decomposition';

    public static function products()
    {
        return PowerMarketProduct::where([
            'resource_type' => 'model',
            'model_type' => 'other',
            'upstream_channel_code' => 'official',
            'upstream_model_code' => self::MODEL,
            'status' => 1,
        ]);
    }

    public static function sizes(int $tenantId): array
    {
        $ids = self::products()->order('id', 'desc')->column('id');
        if (!$ids) return [];
        $catalog = MarketCatalogSkuReader::forProducts($tenantId, $ids);
        $sizes = [];
        foreach ($ids as $id) {
            foreach ($catalog[$id]['available'] ?? [] as $entry) {
                $sku = $entry['sku'];
                $size = (string)($sku['locked_params']['size'] ?? '');
                $contract = MarketImageItemBilling::prices($sku, $tenantId);
                if (($contract['output_limit_field'] ?? '') !== 'max_images' || empty($contract['valid'])) continue;
                $sizes[$size] ??= ['value'=>$size, 'market_product_id'=>(int)$id, 'market_sku_id'=>(int)$sku['id']];
            }
        }
        $ordered = array_replace(['auto'=>null, '1K'=>null, '1.5K'=>null, '2K'=>null], array_intersect_key($sizes, array_flip(['auto','1K','1.5K','2K'])));
        return array_filter($ordered);
    }
}
