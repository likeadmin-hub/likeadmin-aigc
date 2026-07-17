<?php

namespace app\common\model\app\aigc_canvas;

use app\common\model\app\AppBaseModel;

class AigcCanvasAgentToolCall extends AppBaseModel
{
    protected $name = 'aigc_canvas_agent_tool_call';
    protected $json = ['input_json', 'output_json'];
    protected $jsonAssoc = true;
}
