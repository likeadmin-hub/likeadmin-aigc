<?php
declare(strict_types=1);

namespace app\common\service\power;

use app\common\model\power\PowerMarketProduct;

final class MarketAnimateReplace
{
    public const APP = 'aigc_animate_replace';
    public const MODEL = 'wan-2-2-animate-replace';
    public const RESOLUTIONS = ['480p', '580p', '720p'];

    private const PRODUCT = [
        'resource_type' => PowerMarketService::TYPE_MODEL,
        'model_type' => 'other',
        'upstream_model_code' => self::MODEL,
        'upstream_channel_code' => 'official',
    ];

    public static function supportsProduct(array $product): bool
    {
        return array_intersect_assoc(self::PRODUCT, $product) === self::PRODUCT;
    }

    public static function specs(int $tenant): array
    {
        return MarketCatalogReadScope::run(static function () use ($tenant): array {
            $products = PowerMarketProduct::where(self::PRODUCT + ['status'=>1])->order('id', 'desc')->select()->toArray();
            $catalog = MarketCatalogSkuReader::forProducts($tenant, array_column($products, 'id'), ['sort'=>'desc', 'id'=>'asc']);
            $choices = array_fill_keys(self::RESOLUTIONS, null);
            foreach ($products as $product) {
                $metadata = MarketGenerationSchema::array($product['source_payload'] ?? [])['market_metadata'] ?? [];
                $mode = MarketGenerationMaterials::modes($metadata)[0] ?? null;
                if (!$mode || $mode['method'] !== 'omni_reference') continue;
                foreach ($catalog[$product['id']]['available'] ?? [] as $entry) {
                    $sku = $entry['sku'];
                    $locked = MarketGenerationSchema::array($sku['locked_params'] ?? []);
                    $resolution = strtolower((string)($locked['resolution'] ?? ''));
                    if (!in_array($resolution, self::RESOLUTIONS, true)
                        || ($sku['usage_unit'] ?? '') !== 'output_second'
                        || (float)$entry['tenant_price'] <= 0 || (float)$sku['sale_points'] <= 0) continue;
                    $choices[$resolution] ??= [
                        'model_id' => 'market_video_model:'.$product['id'],
                        'market_product_id' => (int)$product['id'],
                        'market_sku_id' => (int)$sku['id'],
                        'resource_type' => PowerMarketService::TYPE_MODEL,
                        'material_mode' => $mode['key'],
                        'generation_method' => $mode['method'],
                    ];
                }
            }
            return array_filter($choices);
        });
    }
}
