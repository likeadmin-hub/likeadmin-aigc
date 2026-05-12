<?php

namespace app\common\model\app\aigc_digital_human;

use app\common\model\app\AppBaseModel;

class AigcDigitalHumanConfig extends AppBaseModel
{
    protected $name = 'aigc_digital_human_config';
    protected $json = ['config_json'];
    protected $jsonAssoc = true;
}

