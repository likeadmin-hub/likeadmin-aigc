<?php

namespace app\common\model\power;

use app\common\model\app\AppBaseModel;

class PowerMarketProduct extends AppBaseModel
{
    protected $name = 'power_market_product';
    protected $json = ['source_payload'];
    protected $jsonAssoc = true;
}
