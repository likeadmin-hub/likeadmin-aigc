<?php

namespace app\platformapi\controller\tenant;

use app\platformapi\controller\BaseAdminController;
use app\platformapi\lists\tenant\TenantPowerConsumeLists;

class PowerConsumeController extends BaseAdminController
{
    public function lists()
    {
        return $this->dataLists(new TenantPowerConsumeLists());
    }
}
