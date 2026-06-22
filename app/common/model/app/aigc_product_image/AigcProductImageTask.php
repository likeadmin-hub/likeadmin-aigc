<?php

namespace app\common\model\app\aigc_product_image;

use app\common\model\app\AppBaseModel;

class AigcProductImageTask extends AppBaseModel
{
    protected $name = 'aigc_product_image_task';
    protected $json = ['image_task_ids'];
    protected $jsonAssoc = true;
}

