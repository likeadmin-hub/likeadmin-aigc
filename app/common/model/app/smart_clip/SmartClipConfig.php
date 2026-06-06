<?php

namespace app\common\model\app\smart_clip;

use app\common\model\app\AppBaseModel;

class SmartClipConfig extends AppBaseModel
{
    protected $name = 'smart_clip_config';
    protected $json = ['config_json'];
    protected $jsonAssoc = true;
}
