<?php

namespace app\common\model\app\aigc_video;

use app\common\model\app\AppBaseModel;

class AigcVideoChannelSpec extends AppBaseModel
{
    protected $name = 'aigc_video_channel_spec';
    protected $json = ['provider_params_json'];
    protected $jsonAssoc = true;
}

