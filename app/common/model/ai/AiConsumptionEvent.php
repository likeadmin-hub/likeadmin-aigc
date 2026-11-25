<?php

namespace app\common\model\ai;

use app\common\model\app\AppBaseModel;

class AiConsumptionEvent extends AppBaseModel
{
    public const SUPPLIER_COST_CONFIRMED = 'supplier_cost_confirmed';

    protected $name = 'ai_consumption_event';
    protected $json = ['payload_summary'];
    protected $jsonAssoc = true;
}
