<?php

namespace app\common\model\app\aigc_person_replacement;

use app\common\model\app\AppBaseModel;

class AigcPersonReplacementConfig extends AppBaseModel
{
    protected $name = 'aigc_person_replacement_config';
    protected $json = ['price_matrix', 'config_json'];
    protected $jsonAssoc = true;
}
