<?php

namespace app\common\model\recharge;

use app\common\model\app\AppBaseModel;
use think\model\concern\SoftDelete;

class RechargePackage extends AppBaseModel
{
    use SoftDelete;

    protected $name = 'recharge_package';
    protected $deleteTime = 'delete_time';
}
