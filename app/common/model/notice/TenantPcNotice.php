<?php

namespace app\common\model\notice;

use app\common\model\app\AppBaseModel;
use think\model\concern\SoftDelete;

class TenantPcNotice extends AppBaseModel
{
    use SoftDelete;

    protected $name = 'tenant_pc_notice';
    protected $deleteTime = 'delete_time';
}
