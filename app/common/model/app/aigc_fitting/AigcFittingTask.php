<?php

namespace app\common\model\app\aigc_fitting;

use app\common\model\app\AppBaseModel;

class AigcFittingTask extends AppBaseModel
{
    protected $name = 'aigc_fitting_task';
    protected $json = ['image_task_ids', 'garment_images', 'model_images', 'selected_preset_ids'];
    protected $jsonAssoc = true;
}
