<?php

namespace app\common\model\app\aigc_video;

use app\common\model\app\AppBaseModel;

class AigcVideoConfig extends AppBaseModel
{
    protected $name = 'aigc_video_config';
    protected $json = ['config_json'];
    protected $jsonAssoc = true;
}

