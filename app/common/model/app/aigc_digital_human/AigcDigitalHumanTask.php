<?php

namespace app\common\model\app\aigc_digital_human;

use app\common\model\app\AppBaseModel;

class AigcDigitalHumanTask extends AppBaseModel
{
    protected $name = 'aigc_digital_human_task';
    protected $json = ['provider_payload_json'];
    protected $jsonAssoc = true;
}
