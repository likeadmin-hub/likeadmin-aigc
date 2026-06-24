<?php

namespace app\common\model\app\aigc_image_translate;

use app\common\model\app\AppBaseModel;

class AigcImageTranslateTask extends AppBaseModel
{
    protected $name = 'aigc_image_translate_task';
    protected $json = ['image_task_ids', 'price_package_snapshot'];
    protected $jsonAssoc = true;
}
