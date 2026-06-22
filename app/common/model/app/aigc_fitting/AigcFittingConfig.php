<?php

namespace app\common\model\app\aigc_fitting;

use app\common\model\app\AppBaseModel;

class AigcFittingConfig extends AppBaseModel
{
    protected $name = 'aigc_fitting_config';
    protected $json = ['config_json'];
    protected $jsonAssoc = true;
}
