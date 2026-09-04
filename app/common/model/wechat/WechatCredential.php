<?php

namespace app\common\model\wechat;

use app\common\model\BaseModel;

class WechatCredential extends BaseModel
{
    protected $name = 'wechat_credentials';
    protected $globalScope = [];
    protected $autoWriteTimestamp = false;
}
