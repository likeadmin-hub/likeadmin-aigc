<?php

namespace app\common\model\app\aigc_photo_restore;

use app\common\model\app\AppBaseModel;

class AigcPhotoRestoreConfig extends AppBaseModel
{
    protected $name = 'aigc_photo_restore_config';
    protected $json = ['price_config', 'config_json'];
    protected $jsonAssoc = true;
}

