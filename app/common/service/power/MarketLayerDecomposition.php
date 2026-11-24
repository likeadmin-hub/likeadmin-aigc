<?php
declare(strict_types=1);

namespace app\common\service\power;

use app\common\model\power\PowerMarketProduct;

final class MarketLayerDecomposition
{
    public const APP = 'aigc_layer_decomposition';
    public const MODEL = 'seedream-5-5-layer-decomposition';
    public const FLASH = 'seedream-5-flash-layer-decomposition';
    public const PRO = 'seedream-5-pro-layer-decomposition';
    public const STYLE = 'layer_decomposition';
    public const MODEL_SETTING = 'layer_decomposition_model';
    private const NAMES = [
        self::MODEL => 'Seedream 5.5',
        self::FLASH => 'Seedream 5.0 Flash',
        self::PRO => 'Seedream 5.0 Pro',
    ];

    public static function products(?string $code = null)
    {
        return PowerMarketProduct::where([
            'resource_type' => 'model',
            'model_type' => 'other',
            'upstream_channel_code' => 'official',
            'status' => 1,
        ])->whereIn('upstream_model_code', $code === null ? array_keys(self::NAMES) : array_intersect(array_keys(self::NAMES), [$code]));
    }

    public static function profile(string $code): ?array
    {
        if (!isset(self::NAMES[$code])) return null;
        $advanced = $code === self::MODEL;
        return [
            'code' => $code,
            'name' => self::NAMES[$code],
            'max_output' => $advanced ? 100 : 17,
            'configurable_output' => $advanced,
            'text_recognition' => $advanced,
            'prompt_min' => $code === self::FLASH ? 4 : 0,
            'prompt_max' => $advanced ? 2000 : 5000,
            'image_max_mb' => $advanced ? 10 : 30,
            'image_formats' => $advanced ? ['image/jpeg', 'image/png', 'image/webp'] : ['image/jpeg', 'image/png'],
            'billing' => $advanced ? 'input_and_output' : 'output_only',
        ];
    }

    public static function fixedOutputLimit(array $product): ?int
    {
        $code = $product['upstream_model_code'] ?? '';
        return ($product['upstream_channel_code'] ?? '') === 'official'
            && in_array($code, [self::FLASH, self::PRO], true) ? self::profile($code)['max_output'] : null;
    }

    public static function supportsFixedOutputBilling(array $sku): bool
    {
        try {
            $meter = MarketGenerationMeter::contract($sku);
        } catch (\InvalidArgumentException) {
            return false;
        }
        $source = MarketGenerationSchema::array($sku['source_payload'] ?? []);
        return !isset($source['image_item_billing'])
            && ($sku['usage_unit'] ?? '') === 'image'
            && MarketUsageSettlementService::unitSize($sku) === 1.0
            && MarketGenerationMeter::isExplicit($meter)
            && ($meter['basis'] ?? '') === 'image'
            && empty($meter['image_denominator']);
    }

    /** Product and SKU identities are resolved on the server from active market and tenant listings. */
    public static function catalog(int $tenantId, ?string $code = null): array
    {
        return MarketCatalogReadScope::run(static function () use ($tenantId, $code): array {
            $products = self::products($code)->order('id', 'desc')->select()->toArray();
            $catalog = MarketCatalogSkuReader::forProducts($tenantId, array_column($products, 'id'), ['id'=>'asc']);
            $models = [];
            foreach ($products as $product) {
                $model = $product['upstream_model_code'];
                foreach ($catalog[$product['id']]['available'] ?? [] as $entry) {
                    $sku = $entry['sku'];
                    $size = (string)($sku['locked_params']['size'] ?? '');
                    if (!in_array($size, ['auto', '1K', '1.5K', '2K'], true)) continue;
                    if ($model === self::MODEL) {
                        $pricing = MarketImageItemBilling::prices($sku, $tenantId);
                        if (($pricing['output_limit_field'] ?? '') !== 'max_images' || empty($pricing['valid'])) continue;
                    } elseif (!self::supportsFixedOutputBilling($sku) || (float)$sku['sale_points'] <= 0 || $entry['tenant_price'] <= 0) {
                        continue;
                    }
                    $models[$model][$size] ??= [
                        'value' => $size,
                        'market_product_id' => (int)$product['id'],
                        'market_sku_id' => (int)$sku['id'],
                    ];
                }
            }
            $order = array_fill_keys(['auto', '1K', '1.5K', '2K'], null);
            foreach ($models as &$sizes) {
                $sizes = array_filter(array_replace($order, $sizes));
            }
            unset($sizes);
            return array_replace(array_intersect_key(self::NAMES, $models), $models);
        });
    }
}
