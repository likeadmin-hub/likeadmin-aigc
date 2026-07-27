<?php

namespace app\common\model\app\aigc_canvas;

use app\common\model\app\AppBaseModel;

class AigcCanvasDeliveryItem extends AppBaseModel
{
    protected $name = 'aigc_canvas_delivery_item';
    protected $json = [
        'depends_on_json',
        'required_slots_json',
        'soft_slots_json',
        'slots_json',
        'delivery_json',
        'creative_context_json',
        'reference_assets_json',
        'pending_action_json',
        'skill_snapshot_json',
        'task_snapshot_json',
        'cost_json',
        'result_json',
        'meta_json',
    ];
    protected $jsonAssoc = true;
}
