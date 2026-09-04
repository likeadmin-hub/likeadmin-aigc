<?php

namespace app\common\model\app\aigc_watermark_removal;

use app\common\model\app\AppBaseModel;

class AigcWatermarkRemovalTask extends AppBaseModel
{
    protected $name = 'aigc_watermark_removal_task';
    protected $json = ['request_snapshot', 'pricing_snapshot'];
    protected $jsonAssoc = true;
}
