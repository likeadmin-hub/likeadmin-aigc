<?php

namespace app\common\model\app\aigc_canvas;

use app\common\model\app\AppBaseModel;

class AigcCanvasProject extends AppBaseModel
{
    protected $name = 'aigc_canvas_project';
    protected $json = ['nodes_json', 'edges_json', 'viewport_json'];
    protected $jsonAssoc = true;
}
