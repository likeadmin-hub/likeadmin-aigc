<?php

namespace app\common\model\power;

use app\common\enum\PayEnum;
use app\common\model\app\AppBaseModel;
use think\model\concern\SoftDelete;

class TenantPowerOrder extends AppBaseModel
{
    use SoftDelete;

    protected $name = 'tenant_power_order';
    protected $deleteTime = 'delete_time';

    public function getPayWayTextAttr($value, $data)
    {
        return PayEnum::getPayDesc($data['pay_way'] ?? 0);
    }

    public function getPayStatusTextAttr($value, $data)
    {
        return PayEnum::getPayStatusDesc($data['pay_status'] ?? 0);
    }
}
