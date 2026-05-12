<?php

namespace app\common\model\app\aigc_canvas;

use app\common\model\app\AppBaseModel;

class AigcCanvasRun extends AppBaseModel
{
    protected $name = 'aigc_canvas_run';
    protected $json = ['params_json', 'result_json'];
    protected $jsonAssoc = true;
}
