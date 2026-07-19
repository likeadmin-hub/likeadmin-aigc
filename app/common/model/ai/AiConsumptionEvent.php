<?php

namespace app\common\model\ai;

use app\common\model\app\AppBaseModel;

class AiConsumptionEvent extends AppBaseModel
{
    protected $name = 'ai_consumption_event';
    protected $json = ['payload_summary'];
    protected $jsonAssoc = true;
}
