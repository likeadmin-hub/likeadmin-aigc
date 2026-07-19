<?php

namespace app\common\model\brand;

use app\common\enum\PayEnum;
use app\common\model\app\AppBaseModel;
use think\model\concern\SoftDelete;

class TenantBrandQuotaOrder extends AppBaseModel
{
    use SoftDelete;

    protected $name = 'tenant_brand_quota_order';
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

