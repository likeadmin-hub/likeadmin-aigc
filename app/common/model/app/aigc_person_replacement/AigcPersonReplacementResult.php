<?php

namespace app\common\model\app\aigc_person_replacement;

use app\common\model\app\AppBaseModel;

class AigcPersonReplacementResult extends AppBaseModel
{
    protected $name = 'aigc_person_replacement_result';
    protected $json = ['result_json'];
    protected $jsonAssoc = true;
}
