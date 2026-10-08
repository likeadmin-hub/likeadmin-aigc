<?php

namespace app\common\model\app\aigc_pic_lipsync;

use app\common\model\app\AppBaseModel;

class AigcPicLipsyncConfig extends AppBaseModel
{
    protected $name = 'aigc_pic_lipsync_config';
    protected $json = ['config_json'];
    protected $jsonAssoc = true;
}
