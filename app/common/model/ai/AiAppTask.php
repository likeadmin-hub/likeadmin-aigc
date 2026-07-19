<?php

namespace app\common\model\ai;

use app\common\model\app\AppBaseModel;

class AiAppTask extends AppBaseModel
{
    protected $name = 'ai_app_task';
    protected $json = ['request_summary', 'result_summary'];
    protected $jsonAssoc = true;
}
