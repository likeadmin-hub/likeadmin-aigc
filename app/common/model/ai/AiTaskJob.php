<?php

namespace app\common\model\ai;

use app\common\model\app\AppBaseModel;

class AiTaskJob extends AppBaseModel
{
    protected $name = 'ai_task_job';
    protected $json = ['payload'];
    protected $jsonAssoc = true;
}
