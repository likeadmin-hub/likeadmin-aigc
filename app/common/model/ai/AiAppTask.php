<?php

namespace app\common\model\ai;

use app\common\model\app\AppBaseModel;

class AiAppTask extends AppBaseModel
{
    protected $name = 'ai_app_task';
    protected $json = ['request_summary', 'result_summary'];
    protected $jsonAssoc = true;

    // Unit prices retain six decimals; account amounts use the existing two-decimal wallet precision.
    public function setEstimatedTenantCostAttr($value): float { return round(max(0, (float)$value), 2); }
    public function setEstimatedUserPriceAttr($value): float { return round(max(0, (float)$value), 2); }
    public function setActualTenantCostAttr($value): float { return round(max(0, (float)$value), 2); }
    public function setActualUserPriceAttr($value): float { return round(max(0, (float)$value), 2); }
}
