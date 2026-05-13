<?php

namespace app\common\model\app\aigc_image;

use app\common\model\app\AppBaseModel;

class AigcImageChannel extends AppBaseModel
{
    protected $name = 'aigc_image_channel';
    protected $json = ['config_json'];
    protected $jsonAssoc = true;
}

