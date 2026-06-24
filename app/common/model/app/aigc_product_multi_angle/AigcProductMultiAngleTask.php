<?php

namespace app\common\model\app\aigc_product_multi_angle;

use app\common\model\app\AppBaseModel;

class AigcProductMultiAngleTask extends AppBaseModel
{
    protected $name = 'aigc_product_multi_angle_task';
    protected $json = ['image_task_ids', 'view_codes', 'view_snapshot'];
    protected $jsonAssoc = true;
}
