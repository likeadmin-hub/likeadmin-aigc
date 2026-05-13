<?php

namespace app\common\model\app\aigc_image;

use app\common\model\app\AppBaseModel;

class AigcImageTask extends AppBaseModel
{
    protected $name = 'aigc_image_task';
    protected $json = ['reference_images'];
    protected $jsonAssoc = true;
}
