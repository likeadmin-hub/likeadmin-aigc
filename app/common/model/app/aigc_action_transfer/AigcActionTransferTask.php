<?php

namespace app\common\model\app\aigc_action_transfer;

use app\common\model\app\AppBaseModel;

class AigcActionTransferTask extends AppBaseModel
{
    protected $name = 'aigc_action_transfer_task';
    protected $json = ['reference_images', 'request_snapshot', 'upstream_usage', 'provider_response'];
    protected $jsonAssoc = true;
}
