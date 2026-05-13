<?php

namespace app\common\model\app\aigc_digital_human;

use app\common\model\app\AppBaseModel;

class AigcDigitalHumanChannelSpec extends AppBaseModel
{
    protected $name = 'aigc_digital_human_channel_spec';
    protected $json = ['provider_params_json'];
    protected $jsonAssoc = true;
}

