<?php

namespace app\common\model\app\aigc_video;

use app\common\model\app\AppBaseModel;

class AigcVideoChannel extends AppBaseModel
{
    protected $name = 'aigc_video_channel';
    protected $json = ['config_json'];
    protected $jsonAssoc = true;
}

