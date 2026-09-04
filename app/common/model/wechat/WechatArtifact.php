<?php

namespace app\common\model\wechat;

use app\common\model\BaseModel;

class WechatArtifact extends BaseModel
{
    protected $name = 'wechat_artifacts';
    protected $globalScope = [];
    protected $autoWriteTimestamp = false;
}
