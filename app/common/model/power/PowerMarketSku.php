<?php

namespace app\common\model\power;

use app\common\model\app\AppBaseModel;

class PowerMarketSku extends AppBaseModel
{
    protected $name = 'power_market_sku';
    protected $json = ['locked_params', 'selectable_params', 'source_payload'];
    protected $jsonAssoc = true;
    protected $append = ['configured_sale_points', 'price_rule'];

    public function getSalePointsAttr($value, $data)
    {
        $data['sale_points'] = $value;
        return (float)\app\common\service\power\MarketPriceRuleService::platform($data)['price'];
    }

    public function getConfiguredSalePointsAttr($value, $data)
    {
        return (float)($data['sale_points'] ?? 0);
    }

    public function getPriceRuleAttr($value, $data)
    {
        return \app\common\service\power\MarketPriceRuleService::platform($data);
    }
}
