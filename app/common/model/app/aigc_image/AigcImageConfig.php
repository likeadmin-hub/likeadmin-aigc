<?php

namespace app\common\model\app\aigc_image;

use app\common\model\app\AppBaseModel;

class AigcImageConfig extends AppBaseModel
{
    protected $name = 'aigc_image_config';
    protected $json = ['config_json'];
    protected $jsonAssoc = true;
}

