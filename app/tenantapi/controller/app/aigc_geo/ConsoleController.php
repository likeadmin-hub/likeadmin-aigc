<?php

namespace app\tenantapi\controller\app\aigc_geo;

use app\common\service\app\aigc_geo\AigcGeoService;
use app\tenantapi\controller\BaseAdminController;

class ConsoleController extends BaseAdminController
{
    public function summary()
    {
        return $this->success('获取成功', AigcGeoService::summary($this->tenantId));
    }
}
