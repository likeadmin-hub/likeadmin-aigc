<?php

namespace app\common\model\app\aigc_product_suite;

use app\common\model\app\AppBaseModel;

class AigcProductSuiteTask extends AppBaseModel
{
    protected $name = 'aigc_product_suite_task';
    protected $json = ['image_task_ids', 'product_images', 'module_codes', 'module_snapshot'];
    protected $jsonAssoc = true;
}
