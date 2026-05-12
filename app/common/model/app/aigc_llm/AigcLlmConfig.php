<?php

namespace app\common\model\app\aigc_llm;

use app\common\model\app\AppBaseModel;

class AigcLlmConfig extends AppBaseModel
{
    protected $name = 'aigc_llm_config';
    protected $json = ['config_json'];
    protected $jsonAssoc = true;
}
