<?php

namespace app\common\model\app\aigc_llm;

use app\common\model\app\AppBaseModel;

class AigcLlmMessage extends AppBaseModel
{
    protected $name = 'aigc_llm_message';
    protected $json = ['token_usage_json'];
    protected $jsonAssoc = true;
}
