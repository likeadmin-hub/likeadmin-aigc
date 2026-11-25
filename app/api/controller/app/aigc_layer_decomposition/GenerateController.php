<?php

namespace app\api\controller\app\aigc_layer_decomposition;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_layer_decomposition\LayerDecompositionService as Service;

class GenerateController extends BaseApiController
{
    public function estimate()
    {
        try {
            return $this->success('操作成功', Service::estimate((int)$this->request->tenantId, $this->userId, $this->request->post()));
        } catch (\Exception $error) {
            return $this->fail($error->getMessage());
        }
    }

    public function index()
    {
        try {
            return $this->success('操作成功', Service::generate((int)$this->request->tenantId, $this->userId, $this->request->post()));
        } catch (\Exception $error) {
            return $this->fail($error->getMessage());
        }
    }
}
