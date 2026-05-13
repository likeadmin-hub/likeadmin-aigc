<?php

namespace app\common\model\update;

use app\common\model\BaseModel;

class UpdateTask extends BaseModel
{
    protected $name = 'update_task';
    protected $globalScope = [];
    protected $json = ['preflight_json', 'result_json'];
    protected $jsonAssoc = true;
}

