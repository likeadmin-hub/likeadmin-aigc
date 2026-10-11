<?php

namespace app\common\model\app;

use app\common\model\BaseModel;

class AppBaseModel extends BaseModel
{
    protected $globalScope = [];
    public static function onAfterInsert($model): void
    {
        \app\common\service\open_platform\OpenCallContext::linkNativeTask($model);
    }
}

