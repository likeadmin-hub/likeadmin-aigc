<?php

namespace app\common\model\app\aigc_video;

use app\common\model\app\AppBaseModel;

class AigcVideoTask extends AppBaseModel
{
    protected $name = 'aigc_video_task';
    protected $json = ['reference_images'];
    protected $jsonAssoc = true;
}
