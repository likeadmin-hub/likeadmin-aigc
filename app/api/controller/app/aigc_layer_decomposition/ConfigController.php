<?php

namespace app\api\controller\app\aigc_layer_decomposition;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_layer_decomposition\LayerDecompositionService as Service;

class ConfigController extends BaseApiController
{
    public function detail()
    {
        return $this->success('获取成功', Service::config((int)$this->request->tenantId));
    }
}
