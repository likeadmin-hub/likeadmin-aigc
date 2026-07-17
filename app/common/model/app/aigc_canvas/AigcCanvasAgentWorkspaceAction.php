<?php

namespace app\common\model\app\aigc_canvas;

use app\common\model\app\AppBaseModel;

class AigcCanvasAgentWorkspaceAction extends AppBaseModel
{
    protected $name = 'aigc_canvas_agent_workspace_action';
    protected $json = ['input_json', 'result_json'];
    protected $jsonAssoc = true;
}
