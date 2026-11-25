<?php

namespace app\api\controller\app\aigc_layer_decomposition;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_layer_decomposition\LayerDecompositionService as Service;

class TaskController extends BaseApiController
{
    public function lists()
    {
        try {
            return $this->success('操作成功', Service::tasks((int)$this->request->tenantId, $this->userId, $this->request->get()));
        } catch (\Exception $error) {
            return $this->fail($error->getMessage());
        }
    }

    public function detail()
    {
        try {
            return $this->success('操作成功', Service::detail((int)$this->request->tenantId, $this->userId, (int)$this->request->get('id', 0)));
        } catch (\Exception $error) {
            return $this->fail($error->getMessage());
        }
    }

    public function delete()
    {
        try {
            Service::deleteTask((int)$this->request->tenantId, $this->userId, (int)$this->request->post('id', 0));
            return $this->success('删除成功');
        } catch (\Exception $error) {
            return $this->fail($error->getMessage());
        }
    }
}
