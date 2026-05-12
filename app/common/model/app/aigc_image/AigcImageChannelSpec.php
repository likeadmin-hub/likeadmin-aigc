<?php

namespace app\common\model\app\aigc_image;

use app\common\model\app\AppBaseModel;

class AigcImageChannelSpec extends AppBaseModel
{
    protected $name = 'aigc_image_channel_spec';
    protected $json = ['provider_params_json'];
    protected $jsonAssoc = true;
}

