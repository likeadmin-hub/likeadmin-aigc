<?php

namespace app\common\model\app\aigc_background_removal;

use app\common\model\app\AppBaseModel;

class AigcBackgroundRemovalConfig extends AppBaseModel
{
    protected $name = 'aigc_background_removal_config';
    protected $json = ['price_config', 'config_json'];
    protected $jsonAssoc = true;
}

