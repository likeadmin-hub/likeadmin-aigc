<?php

namespace app\common\model\app\aigc_local_redraw;

use app\common\model\app\AppBaseModel;

class AigcLocalRedrawTask extends AppBaseModel
{
    protected $name = 'aigc_local_redraw_task';
    protected $json = ['image_task_ids'];
    protected $jsonAssoc = true;
}
