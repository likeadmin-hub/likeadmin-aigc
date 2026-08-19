<?php

namespace app\common\model\ai;

use app\common\model\app\AppBaseModel;

class AiMarketAppGate extends AppBaseModel
{
    protected $name = 'ai_market_app_gate';
    protected $json = ['user_whitelist'];
    protected $jsonAssoc = true;
}
