<?php

namespace app\tenantapi\controller\app\aigc_layer_decomposition;

use app\tenantapi\controller\BaseAdminController;
use app\common\service\app\aigc_layer_decomposition\LayerDecompositionService as Service;
use app\common\service\app\AppDisplayConfigService;
use app\common\service\power\MarketLayerDecomposition as Market;

class ConfigController extends BaseAdminController
{
    public function detail()
    {
        return $this->success('获取成功', Service::config($this->tenantId));
    }

    public function setup()
    {
        AppDisplayConfigService::saveFromConfigPayload($this->tenantId, Market::APP, $this->request->post());
        return $this->success('保存成功');
    }
}
