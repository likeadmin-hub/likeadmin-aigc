<?php

namespace app\common\model\decorate;

use app\common\model\BaseModel;
use think\model\concern\SoftDelete;

class DecorateTemplate extends BaseModel
{
    use SoftDelete;

    protected $globalScope = [];
    protected $deleteTime = 'delete_time';
}
