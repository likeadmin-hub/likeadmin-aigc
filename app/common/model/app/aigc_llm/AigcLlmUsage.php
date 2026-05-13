<?php

namespace app\common\model\app\aigc_llm;

use app\common\model\app\AppBaseModel;

class AigcLlmUsage extends AppBaseModel
{
    protected $name = 'aigc_llm_usage';
    protected $json = ['price_json', 'extra_json'];
    protected $jsonAssoc = true;
}
