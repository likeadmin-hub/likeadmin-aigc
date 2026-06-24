<?php

namespace app\common\model\app\aigc_fashion_lookbook;

use app\common\model\app\AppBaseModel;

class AigcFashionLookbookConfig extends AppBaseModel
{
    protected $name = 'aigc_fashion_lookbook_config';
    protected $json = ['config_json'];
    protected $jsonAssoc = true;
}
