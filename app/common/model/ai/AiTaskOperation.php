<?php

namespace app\common\model\ai;

use app\common\model\app\AppBaseModel;

class AiTaskOperation extends AppBaseModel
{
    protected $name = 'ai_task_operation';
    protected $json = ['summary'];
    protected $jsonAssoc = true;
}
