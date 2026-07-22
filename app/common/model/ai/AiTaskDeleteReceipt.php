<?php

namespace app\common\model\ai;

use app\common\model\app\AppBaseModel;

class AiTaskDeleteReceipt extends AppBaseModel
{
    protected $name = 'ai_task_delete_receipt';
    protected $json = ['target_summary', 'delete_summary'];
    protected $jsonAssoc = true;
}
