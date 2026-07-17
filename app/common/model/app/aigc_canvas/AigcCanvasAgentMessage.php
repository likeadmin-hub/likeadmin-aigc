<?php

namespace app\common\model\app\aigc_canvas;

use app\common\model\app\AppBaseModel;

class AigcCanvasAgentMessage extends AppBaseModel
{
    protected $name = 'aigc_canvas_agent_message';
    protected $json = ['content_json', 'meta_json'];
    protected $jsonAssoc = true;
}
