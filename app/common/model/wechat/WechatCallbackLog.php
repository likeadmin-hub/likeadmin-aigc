<?php

namespace app\common\model\wechat;

use app\common\model\BaseModel;

class WechatCallbackLog extends BaseModel
{
    protected $name = 'wechat_callback_logs';
    protected $globalScope = [];
    protected $autoWriteTimestamp = false;
}
