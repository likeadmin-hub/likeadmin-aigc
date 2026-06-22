<?php

namespace app\common\model\app\aigc_hairstyle;

use app\common\model\app\AppBaseModel;

class AigcHairstyleConfig extends AppBaseModel
{
    protected $name = 'aigc_hairstyle_config';
    protected $json = ['config_json'];
    protected $jsonAssoc = true;
}
