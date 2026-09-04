<?php

namespace app\common\model\app\aigc_watermark_removal;

use app\common\model\app\AppBaseModel;

class AigcWatermarkRemovalConfig extends AppBaseModel
{
    protected $name = 'aigc_watermark_removal_config';
    protected $json = ['config_json'];
    protected $jsonAssoc = true;
}
