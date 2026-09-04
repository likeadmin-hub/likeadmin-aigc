<?php

namespace app\tenantapi\controller\app\aigc_geo;

use app\common\service\app\aigc_geo\AigcGeoService;
use app\tenantapi\controller\BaseAdminController;
use Exception;

class ConfigController extends BaseAdminController
{
    public function detail()
    {
        return $this->success('获取成功', AigcGeoService::config($this->tenantId));
    }

    public function setup()
    {
        try {
            return $this->success('保存成功', AigcGeoService::saveConfig($this->tenantId, $this->request->post()), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
