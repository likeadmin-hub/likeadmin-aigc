<?php

namespace app\common\model\tenant;

use app\common\model\app\AppBaseModel;
use think\model\concern\SoftDelete;

class TenantDomainAlias extends AppBaseModel
{
    use SoftDelete;

    protected $name = 'tenant_domain_alias';
    protected $deleteTime = 'delete_time';
}

