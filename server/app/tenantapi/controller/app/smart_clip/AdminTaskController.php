<?php

namespace app\tenantapi\controller\app\smart_clip;

use app\common\service\app\smart_clip\SmartClipService;
use app\tenantapi\controller\BaseAdminController;

class AdminTaskController extends BaseAdminController
{
    public function lists()
    {
        return $this->success('获取成功', SmartClipService::taskLists($this->tenantId, 0, $this->request->get()));
    }

    public function detail()
    {
        try {
            $id = (int)$this->request->get('id', 0);
            return $this->success('获取成功', SmartClipService::taskDetail($this->tenantId, $id));
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function retry()
    {
        try {
            $id = (int)$this->request->post('id', 0);
            return $this->success('重试成功', SmartClipService::retryTask($this->tenantId, $id), 1, 1);
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function delete()
    {
        try {
            $id = (int)$this->request->post('id', 0);
            SmartClipService::deleteTask($this->tenantId, $id);
            return $this->success('删除成功', [], 1, 1);
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
