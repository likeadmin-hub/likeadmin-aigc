<?php

namespace app\common\model\app\aigc_background_removal;

use app\common\model\app\AppBaseModel;

class AigcBackgroundRemovalTask extends AppBaseModel
{
    protected $name = 'aigc_background_removal_task';
    protected $json = ['image_task_ids', 'price_package_snapshot'];
    protected $jsonAssoc = true;
}
