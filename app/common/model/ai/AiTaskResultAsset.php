<?php

namespace app\common\model\ai;

use app\common\model\app\AppBaseModel;

class AiTaskResultAsset extends AppBaseModel
{
    protected $name = 'ai_task_result_asset';
    protected $json = ['storage_meta'];
    protected $jsonAssoc = true;
}
