<?php

namespace app\common\model\tenant;

use app\common\model\BaseModel;

class TenantSsoTicket extends BaseModel
{
    protected $name = 'tenant_sso_ticket';

    protected $globalScope = [];
}
