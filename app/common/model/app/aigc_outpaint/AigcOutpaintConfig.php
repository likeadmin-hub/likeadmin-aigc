<?php

namespace app\common\model\app\aigc_outpaint;

use app\common\model\app\AppBaseModel;

class AigcOutpaintConfig extends AppBaseModel
{
    protected $name = 'aigc_outpaint_config';
    protected $json = ['price_config', 'config_json'];
    protected $jsonAssoc = true;
}

