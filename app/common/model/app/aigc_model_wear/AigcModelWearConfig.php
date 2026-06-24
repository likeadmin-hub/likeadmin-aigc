<?php

namespace app\common\model\app\aigc_model_wear;

use app\common\model\app\AppBaseModel;

class AigcModelWearConfig extends AppBaseModel
{
    protected $name = 'aigc_model_wear_config';
    protected $json = ['price_config', 'config_json'];
    protected $jsonAssoc = true;
}

