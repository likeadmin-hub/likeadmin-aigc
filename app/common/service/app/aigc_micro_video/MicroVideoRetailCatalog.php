<?php
namespace app\common\service\app\aigc_micro_video;

use app\common\service\power\MarketCatalogSkuReader;
use app\common\service\power\MarketGenerationMeter;

/** Advisory end-user rates only. Never creates a quote, request, reserve or supplier call. */
final class MicroVideoRetailCatalog
{
    public static function videoModels(int $tenant, array $models): array
    {
        $products = [];
        foreach ($models as $model) foreach ($model['specs'] ?? [] as $spec) $products[] = (int)$spec['market_product_id'];
        // Callable models may be cached; retail rules are always resolved in this read.
        try { $markets = MarketCatalogSkuReader::forProducts($tenant, array_values(array_unique($products))); }
        catch (\Throwable) { $markets = []; }
        return self::projectVideoModels($models, $markets);
    }

    /** Project only currently available, callable SKUs. Never fall back to a cost or a stored quote. */
    public static function projectVideoModels(array $models, array $markets): array
    {
        foreach ($models as &$model) {
            $rates = [];
            foreach ($model['specs'] ?? [] as $spec) {
                foreach ($markets[(int)$spec['market_product_id']]['available'] ?? [] as $market) {
                    $sku = $market['sku'];
                    if ((int)$sku['id'] !== (int)$spec['market_sku_id']) continue;
                    $price = $market['tenant_price'] ?? null;
                    $size = $sku['usage_unit_size'] ?? 1;
                    if (!is_numeric($price) || !is_finite((float)$price) || (float)$price < 0 || !is_numeric($size) || (float)$size <= 0) continue;
                    try { $meter = MarketGenerationMeter::contract($sku); } catch (\Throwable) { continue; }
                    $key = $sku['id'].'|'.$spec['quality'].'|'.$spec['ratio'];
                    if (!isset($rates[$key])) $rates[$key] = [
                        'points' => (float)$price, 'unit' => (string)$sku['usage_unit'], 'unit_size' => max(1.0, (float)$size),
                        'quality' => (string)$spec['quality'], 'ratio' => (string)$spec['ratio'], 'durations' => [],
                        'meter_summary' => (string)$meter['summary'],
                    ];
                    $rates[$key]['durations'][] = (int)$spec['duration'];
                }
            }
            foreach ($rates as &$rate) { $rate['durations'] = array_values(array_unique($rate['durations'])); sort($rate['durations']); } unset($rate);
            $model['reference_price'] = ['status' => $rates ? 'available' : 'unavailable', 'rates' => array_values($rates)];
        }
        unset($model);
        return $models;
    }
}
