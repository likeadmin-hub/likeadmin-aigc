<?php

namespace app\common\model\app\aigc_style_transfer;

use app\common\model\app\AppBaseModel;

class AigcStyleTransferConfig extends AppBaseModel
{
    protected $name = 'aigc_style_transfer_config';
    protected $json = ['config_json'];
    protected $jsonAssoc = true;
}

