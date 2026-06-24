<?php

namespace app\common\model\app\aigc_image_translate;

use app\common\model\app\AppBaseModel;

class AigcImageTranslateConfig extends AppBaseModel
{
    protected $name = 'aigc_image_translate_config';
    protected $json = ['price_config', 'config_json'];
    protected $jsonAssoc = true;
}

