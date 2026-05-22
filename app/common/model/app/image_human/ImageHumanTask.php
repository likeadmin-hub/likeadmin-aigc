<?php

namespace app\common\model\app\image_human;

use app\common\model\app\AppBaseModel;

class ImageHumanTask extends AppBaseModel
{
    protected $name = 'image_human_task';
    protected $json = ['provider_payload_json'];
    protected $jsonAssoc = true;
}
