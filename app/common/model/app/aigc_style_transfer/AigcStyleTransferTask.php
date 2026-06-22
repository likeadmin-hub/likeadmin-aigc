<?php

namespace app\common\model\app\aigc_style_transfer;

use app\common\model\app\AppBaseModel;

class AigcStyleTransferTask extends AppBaseModel
{
    protected $name = 'aigc_style_transfer_task';
    protected $json = ['image_task_ids'];
    protected $jsonAssoc = true;
}

