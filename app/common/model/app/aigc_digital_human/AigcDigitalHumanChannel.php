<?php

namespace app\common\model\app\aigc_digital_human;

use app\common\model\app\AppBaseModel;

class AigcDigitalHumanChannel extends AppBaseModel
{
    protected $name = 'aigc_digital_human_channel';
    protected $json = ['config_json'];
    protected $jsonAssoc = true;
}

