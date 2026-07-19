<?php

namespace app\tenantapi\controller\brand;

use app\tenantapi\controller\BaseAdminController;
use app\tenantapi\lists\brand\TenantBrandOrderLists;

class OrderController extends BaseAdminController
{
    public function lists()
    {
        return $this->dataLists(new TenantBrandOrderLists());
    }
}

