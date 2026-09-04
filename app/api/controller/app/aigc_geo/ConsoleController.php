<?php

namespace app\api\controller\app\aigc_geo;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_geo\AigcGeoService;

class ConsoleController extends BaseApiController
{
    public function summary()
    {
        return $this->success('获取成功', AigcGeoService::summary((int)$this->request->tenantId, $this->userId));
    }
}
