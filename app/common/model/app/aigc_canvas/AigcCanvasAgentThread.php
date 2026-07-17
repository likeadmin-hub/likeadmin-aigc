<?php

namespace app\common\model\app\aigc_canvas;

use app\common\model\app\AppBaseModel;

class AigcCanvasAgentThread extends AppBaseModel
{
    protected $name = 'aigc_canvas_agent_thread';
    protected $json = ['meta_json'];
    protected $jsonAssoc = true;
}
