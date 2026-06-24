<?php

namespace app\common\model\app\aigc_one_click_cleanup;

use app\common\model\app\AppBaseModel;

class AigcOneClickCleanupConfig extends AppBaseModel
{
    protected $name = 'aigc_one_click_cleanup_config';
    protected $json = ['config_json'];
    protected $jsonAssoc = true;
}
