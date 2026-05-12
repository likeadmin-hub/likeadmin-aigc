<?php

namespace app\common\model\app\aigc_llm;

use app\common\model\app\AppBaseModel;

class AigcLlmChannel extends AppBaseModel
{
    protected $name = 'aigc_llm_channel';
    protected $json = ['config_json'];
    protected $jsonAssoc = true;
}
