<?php

namespace app\common\model\power;

use app\common\model\app\AppBaseModel;
use think\model\concern\SoftDelete;

class TenantPowerPackage extends AppBaseModel
{
    use SoftDelete;

    protected $name = 'tenant_power_package';
    protected $deleteTime = 'delete_time';
}
