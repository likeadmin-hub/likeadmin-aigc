<?php

namespace app\common\model\app\smart_clip;

use app\common\model\app\AppBaseModel;

class SmartClipResult extends AppBaseModel
{
    protected $name = 'smart_clip_result';
    protected $json = ['result_json'];
    protected $jsonAssoc = true;
}
