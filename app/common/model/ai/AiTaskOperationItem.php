<?php

namespace app\common\model\ai;

use app\common\model\app\AppBaseModel;

class AiTaskOperationItem extends AppBaseModel
{
    protected $name = 'ai_task_operation_item';
    protected $json = ['before_snapshot', 'after_snapshot'];
    protected $jsonAssoc = true;
}
