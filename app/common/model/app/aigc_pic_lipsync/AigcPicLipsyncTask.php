<?php

namespace app\common\model\app\aigc_pic_lipsync;

use app\common\model\app\AppBaseModel;

class AigcPicLipsyncTask extends AppBaseModel
{
    protected $name = 'aigc_pic_lipsync_task';
    protected $json = ['request_snapshot', 'pricing_snapshot'];
    protected $jsonAssoc = true;
}
