<?php

namespace app\common\model\membership;

use app\common\enum\PayEnum;
use app\common\model\app\AppBaseModel;

class MembershipOrder extends AppBaseModel
{
    protected $name = 'membership_order';

    public function getPayWayTextAttr($value, $data)
    {
        return PayEnum::getPayDesc($data['pay_way'] ?? 0);
    }

    public function getPayStatusTextAttr($value, $data)
    {
        return PayEnum::getPayStatusDesc($data['pay_status'] ?? 0);
    }
}
