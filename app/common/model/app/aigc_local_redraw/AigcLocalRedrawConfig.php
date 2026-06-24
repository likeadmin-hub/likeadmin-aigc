<?php

namespace app\common\model\app\aigc_local_redraw;

use app\common\model\app\AppBaseModel;

class AigcLocalRedrawConfig extends AppBaseModel
{
    protected $name = 'aigc_local_redraw_config';
    protected $json = ['config_json'];
    protected $jsonAssoc = true;
}
