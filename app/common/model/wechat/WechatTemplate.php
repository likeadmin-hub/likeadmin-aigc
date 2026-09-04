<?php

namespace app\common\model\wechat;

use app\common\model\BaseModel;

class WechatTemplate extends BaseModel
{
    protected $name = 'wechat_templates';
    protected $globalScope = [];
    protected $autoWriteTimestamp = false;
}
