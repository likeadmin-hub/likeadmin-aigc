<?php

namespace app\common\model\wechat;

use app\common\model\BaseModel;

class WechatAuthorizer extends BaseModel
{
    protected $name = 'wechat_authorizers';
    protected $globalScope = [];
    protected $autoWriteTimestamp = false;
}
