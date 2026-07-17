<?php

namespace app\common\model\app\aigc_canvas;

use app\common\model\app\AppBaseModel;

class AigcCanvasSkill extends AppBaseModel
{
    protected $name = 'aigc_canvas_skill';
    protected $json = [
        'workflow_json',
        'tool_policy_json',
        'examples_json',
        'negative_examples_json',
        'required_slots_json',
        'optional_slots_json',
        'defaults_json',
        'clarification_policy_json',
        'output_policy_json',
        'agent_policy_json',
        'tool_schema_json',
        'canvas_output_policy_json',
    ];
    protected $jsonAssoc = true;
}
