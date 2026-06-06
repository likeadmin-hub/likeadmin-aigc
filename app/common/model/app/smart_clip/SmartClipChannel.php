<?php

namespace app\common\model\app\smart_clip;

use app\common\model\app\AppBaseModel;

class SmartClipChannel extends AppBaseModel
{
    protected $name = 'smart_clip_channel';
    protected $json = ['config_json'];
    protected $jsonAssoc = true;
}
