<?php

namespace app\tenantapi\controller\app\aigc_image;

use app\common\service\app\aigc_image\AigcImageService;
use app\tenantapi\controller\BaseAdminController;

class AdminTaskController extends BaseAdminController
{
    public function lists()
    {
        return $this->success('获取成功', AigcImageService::taskLists($this->tenantId, 0, $this->request->get()));
    }

    public function detail()
    {
        try {
            $id = (int)$this->request->get('id', 0);
            return $this->success('获取成功', AigcImageService::taskDetail($this->tenantId, $id));
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function retry()
    {
        try {
            $id = (int)$this->request->post('id', 0);
            return $this->success('重试成功', AigcImageService::retryTask($this->tenantId, $id), 1, 1);
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function delete()
    {
        try {
            $id = (int)$this->request->post('id', 0);
            AigcImageService::deleteTask($this->tenantId, $id);
            return $this->success('删除成功', [], 1, 1);
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
