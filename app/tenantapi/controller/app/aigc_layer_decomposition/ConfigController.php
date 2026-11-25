<?php

namespace app\tenantapi\controller\app\aigc_layer_decomposition;

use app\tenantapi\controller\BaseAdminController;
use app\common\service\app\aigc_layer_decomposition\LayerDecompositionService as Service;

class ConfigController extends BaseAdminController
{
    public function detail()
    {
        return $this->success('获取成功', Service::config($this->tenantId, true));
    }

    public function setup()
    {
        try {
            Service::setup($this->tenantId, $this->request->post());
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }
        return $this->success('保存成功');
    }
}
