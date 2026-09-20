<?php

namespace app\common\model\wechat;

use app\common\model\BaseModel;

/** Platform-side records for uploads to the WeChat component draft box. */
class WechatTemplateDraft extends BaseModel
{
    protected $name = 'wechat_template_drafts';
    protected $globalScope = [];
    protected $autoWriteTimestamp = false;
}
