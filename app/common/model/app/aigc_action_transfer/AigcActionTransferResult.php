<?php

namespace app\common\model\app\aigc_action_transfer;

use app\common\model\app\AppBaseModel;

class AigcActionTransferResult extends AppBaseModel
{
    protected $name = 'aigc_action_transfer_result';
    protected $json = ['result_json'];
    protected $jsonAssoc = true;
}
