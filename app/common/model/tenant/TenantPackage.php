<?php

namespace app\common\model\tenant;

use app\common\model\app\AppBaseModel;
use think\model\concern\SoftDelete;

class TenantPackage extends AppBaseModel
{
    use SoftDelete;

    protected $name = 'tenant_package';
    protected $deleteTime = 'delete_time';
}

