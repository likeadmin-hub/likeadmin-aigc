<?php

namespace app\common\model\power;

use app\common\model\app\AppBaseModel;

class PowerMarketSku extends AppBaseModel
{
    protected $name = 'power_market_sku';
    protected $json = ['locked_params', 'selectable_params', 'source_payload'];
    protected $jsonAssoc = true;
}
