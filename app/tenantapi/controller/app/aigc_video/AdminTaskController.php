<?php

namespace app\tenantapi\controller\app\aigc_video;

use app\common\service\app\aigc_video\AigcVideoService;
use app\tenantapi\controller\BaseAdminController;

class AdminTaskController extends BaseAdminController
{
    public function lists()
    {
        try {
            return $this->success('获取成功', AigcVideoService::taskLists($this->tenantId, 0, $this->request->get()));
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function detail()
    {
        try {
            $id = (int)$this->request->get('id', 0);
            return $this->success('获取成功', AigcVideoService::taskDetail($this->tenantId, $id));
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function retry()
    {
        try {
            $id = (int)$this->request->post('id', 0);
            return $this->success('重试成功', AigcVideoService::retryTask($this->tenantId, $id), 1, 1);
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function delete()
    {
        try {
            $id = (int)$this->request->post('id', 0);
            AigcVideoService::deleteTask($this->tenantId, $id);
            return $this->success('删除成功', [], 1, 1);
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
