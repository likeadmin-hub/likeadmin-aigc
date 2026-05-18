<?php

namespace app\common\model\app\image_human;

use app\common\model\app\AppBaseModel;

class ImageHumanConfig extends AppBaseModel
{
    protected $name = 'image_human_config';
    protected $json = ['config_json'];
    protected $jsonAssoc = true;
}
