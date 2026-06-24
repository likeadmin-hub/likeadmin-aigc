<?php

namespace app\common\model\app\aigc_one_click_cleanup;

use app\common\model\app\AppBaseModel;

class AigcOneClickCleanupTask extends AppBaseModel
{
    protected $name = 'aigc_one_click_cleanup_task';
    protected $json = ['image_task_ids', 'source_images', 'option_codes', 'option_snapshot'];
    protected $jsonAssoc = true;
}
