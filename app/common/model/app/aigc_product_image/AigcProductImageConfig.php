<?php

namespace app\common\model\app\aigc_product_image;

use app\common\model\app\AppBaseModel;

class AigcProductImageConfig extends AppBaseModel
{
    protected $name = 'aigc_product_image_config';
    protected $json = ['config_json'];
    protected $jsonAssoc = true;
}

