<?php

namespace app\common\model\ai;

use app\common\model\app\AppBaseModel;

class AiConsumptionLog extends AppBaseModel
{
    protected $name = 'ai_consumption_log';
    protected $json = ['price_snapshot', 'usage_snapshot', 'request_summary', 'response_summary'];
    protected $jsonAssoc = true;
}
