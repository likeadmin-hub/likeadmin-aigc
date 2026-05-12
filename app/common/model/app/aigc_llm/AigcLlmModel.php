<?php

namespace app\common\model\app\aigc_llm;

use app\common\model\app\AppBaseModel;

class AigcLlmModel extends AppBaseModel
{
    protected $name = 'aigc_llm_model';
    protected $json = ['config_json'];
    protected $jsonAssoc = true;
}
