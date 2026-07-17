<?php

namespace app\common\model\app\aigc_canvas;

use app\common\model\app\AppBaseModel;

class AigcCanvasAgentBatch extends AppBaseModel
{
    protected $name = 'aigc_canvas_agent_batch';
    protected $json = [
        'analysis_json',
        'sections_json',
        'references_json',
        'media_config_json',
        'tasks_json',
        'decision_json',
        'scope_json',
    ];
    protected $jsonAssoc = true;
}
