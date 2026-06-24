<?php

namespace app\common\model\app\aigc_fashion_lookbook;

use app\common\model\app\AppBaseModel;

class AigcFashionLookbookTask extends AppBaseModel
{
    protected $name = 'aigc_fashion_lookbook_task';
    protected $json = ['image_task_ids', 'clothes_images', 'model_snapshot'];
    protected $jsonAssoc = true;
}
