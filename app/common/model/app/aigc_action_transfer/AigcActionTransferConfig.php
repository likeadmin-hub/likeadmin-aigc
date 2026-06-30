<?php

namespace app\common\model\app\aigc_action_transfer;

use app\common\model\app\AppBaseModel;

class AigcActionTransferConfig extends AppBaseModel
{
    protected $name = 'aigc_action_transfer_config';
    protected $json = ['price_matrix', 'config_json'];
    protected $jsonAssoc = true;
}
