<?php

namespace app\common\model\tenant;

use app\common\model\BaseModel;

class TenantPointLog extends BaseModel
{
    protected $name = 'tenant_point_log';
    protected $json = ['extra'];
    protected $jsonAssoc = true;
}
