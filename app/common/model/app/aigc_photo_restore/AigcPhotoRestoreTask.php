<?php

namespace app\common\model\app\aigc_photo_restore;

use app\common\model\app\AppBaseModel;

class AigcPhotoRestoreTask extends AppBaseModel
{
    protected $name = 'aigc_photo_restore_task';
    protected $json = ['image_task_ids'];
    protected $jsonAssoc = true;
}

