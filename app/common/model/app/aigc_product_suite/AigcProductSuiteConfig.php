<?php

namespace app\common\model\app\aigc_product_suite;

use app\common\model\app\AppBaseModel;

class AigcProductSuiteConfig extends AppBaseModel
{
    protected $name = 'aigc_product_suite_config';
    protected $json = ['config_json'];
    protected $jsonAssoc = true;
}
