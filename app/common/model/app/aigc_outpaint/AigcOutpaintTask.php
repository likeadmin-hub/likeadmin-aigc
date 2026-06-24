<?php

namespace app\common\model\app\aigc_outpaint;

use app\common\model\app\AppBaseModel;

class AigcOutpaintTask extends AppBaseModel
{
    protected $name = 'aigc_outpaint_task';
    protected $json = ['image_task_ids', 'price_package_snapshot'];
    protected $jsonAssoc = true;
}
