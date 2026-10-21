<?php

namespace app\common\service\app\aigc_digital_human;

use app\common\model\app\aigc_digital_human\AigcDigitalHumanConfig;
use app\common\model\power\PowerMarketProduct;
use app\common\model\power\PowerMarketSku;
use app\common\model\power\TenantPowerMarketSkuPrice;
use Exception;

/** The same application API used by the voice-clone provider, priced per invocation. */
final class VoiceCloneMarketPricingService
{
    public const UPSTREAM_APP = 'voice_tts';
    public const UPSTREAM_API = 'clone_voice';

    public static function config(int $tenantId): array
    {
        try {
            return self::resolve($tenantId);
        } catch (Exception $e) {
            return ['available' => false, 'platform_unit_cost' => null, 'tenant_unit_price' => null,
                'price_source' => 'power_market_app_api', 'unavailable_reason' => '音色克隆市场报价暂不可用，请检查算力市场接口和价格配置'];
        }
    }

    public static function quote(int $tenantId, array $params = []): array
    {
        $row = self::resolve($tenantId);
        if (array_key_exists('expected_clone_price', $params) &&
            (!is_numeric($params['expected_clone_price']) || !is_finite((float)$params['expected_clone_price']) ||
                abs((float)$params['expected_clone_price'] - (float)$row['tenant_unit_price']) > 0.0000005)) {
            throw new Exception('音色克隆价格已更新，请重新确认价格后提交');
        }
        return $row + ['app_code' => 'aigc_digital_human', 'billing_type' => 'voice_clone',
            'billing_unit' => 'count', 'quantity' => 1,
            'tenant_cost_points' => $row['platform_unit_cost'], 'user_charge_points' => $row['tenant_unit_price']];
    }

    private static function resolve(int $tenantId): array
    {
        $products = PowerMarketProduct::where(['resource_type' => 'app_api', 'upstream_app_code' => self::UPSTREAM_APP,
            'upstream_api_code' => self::UPSTREAM_API, 'status' => 1])->select()->toArray();
        if (count($products) !== 1) throw new Exception('音色克隆接口未上架或市场配置不唯一');
        $product = $products[0];
        $skus = PowerMarketSku::where(['product_id' => $product['id'], 'status' => 1, 'sale_status' => 1])->select()->toArray();
        if (count($skus) !== 1) throw new Exception('音色克隆价格未上架或市场规格不唯一');
        $sku = $skus[0];
        if ($sku['usage_unit'] !== 'per_call' || (float)$sku['usage_unit_size'] !== 1.0 || !empty($sku['locked_params'])) {
            throw new Exception('音色克隆接口需要按次固定价规格');
        }
        $cost = self::amount($sku['sale_points']);
        $default = $cost;
        $tenantPrice = $tenantId > 0 ? TenantPowerMarketSkuPrice::where(['tenant_id' => $tenantId, 'sku_id' => $sku['id']])->findOrEmpty() : null;
        if ($tenantPrice && !$tenantPrice->isEmpty()) {
            if ((int)$tenantPrice['sale_status'] !== 1) throw new Exception('当前租户已下架音色克隆接口');
            $default = self::amount(max((float)$cost, (float)self::amount($tenantPrice['sale_points'])));
        }
        $price = $default;
        $source = 'market_default';
        if ($tenantId > 0) {
            $config = AigcDigitalHumanConfig::where('tenant_id', $tenantId)->findOrEmpty();
            $override = $config->isEmpty() ? null : ($config['config_json']['pricing']['voice_clone']['tenant_unit_price'] ?? null);
            if ($override !== null && is_numeric($override) && is_finite((float)$override) && (float)$override >= (float)$cost) {
                $price = self::amount($override);
                $source = 'tool_override';
            }
        }
        return ['available' => true, 'platform_unit_cost' => $cost, 'tenant_unit_price' => $price,
            'default_unit_price' => $default, 'price_source' => 'power_market_app_api', 'user_price_source' => $source,
            'market_product_id' => (int)$product['id'], 'market_sku_id' => (int)$sku['id'],
            'upstream_app_code' => self::UPSTREAM_APP, 'upstream_api_code' => self::UPSTREAM_API,
            'usage_unit' => 'per_call', 'usage_unit_size' => 1];
    }

    public static function amount($value): string
    {
        if (!is_numeric($value) || !is_finite((float)$value) || (float)$value < 0 || (float)$value > 99999999) {
            throw new Exception('音色克隆价格无效');
        }
        if ((float)$value > 0 && round((float)$value, 2) === 0.0) throw new Exception('音色克隆价格低于账户支持的最小精度');
        return number_format((float)$value, 2, '.', '');
    }
}
