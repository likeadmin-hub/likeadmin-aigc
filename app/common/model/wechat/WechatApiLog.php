<?php

namespace app\common\model\wechat;

use app\common\model\BaseModel;

class WechatApiLog extends BaseModel
{
    protected $name = 'wechat_api_logs';
    protected $globalScope = [];
    protected $autoWriteTimestamp = false;
}
