<?php

namespace app\tenantapi\controller\app\aigc_geo;

use app\common\service\app\aigc_geo\AigcGeoService;
use app\tenantapi\controller\BaseAdminController;
use Exception;

class TaskController extends BaseAdminController
{
    public function lists()
    {
        return $this->success('获取成功', AigcGeoService::taskLists($this->tenantId, $this->request->get()));
    }

    public function detail()
    {
        try {
            return $this->success('获取成功', AigcGeoService::taskDetail($this->tenantId, (int)$this->request->get('id', 0)));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
