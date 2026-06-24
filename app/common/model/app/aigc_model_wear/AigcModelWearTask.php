<?php

namespace app\common\model\app\aigc_model_wear;

use app\common\model\app\AppBaseModel;

class AigcModelWearTask extends AppBaseModel
{
    protected $name = 'aigc_model_wear_task';
    protected $json = ['image_task_ids', 'price_package_snapshot'];
    protected $jsonAssoc = true;
}
