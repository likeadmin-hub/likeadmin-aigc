<?php

namespace app\tenantapi\controller\app\aigc_one_click_cleanup;

use app\common\service\app\aigc_one_click_cleanup\AigcOneClickCleanupService;
use app\tenantapi\controller\BaseAdminController;
use Exception;

class TaskController extends BaseAdminController
{
    public function lists()
    {
        return $this->success('获取成功', AigcOneClickCleanupService::taskLists($this->tenantId, 0, $this->request->get()));
    }

    public function detail()
    {
        try {
            return $this->success('获取成功', AigcOneClickCleanupService::taskDetail($this->tenantId, (int)$this->request->get('id', 0)));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function retry()
    {
        try {
            return $this->success('重试成功', AigcOneClickCleanupService::retryTask($this->tenantId, (int)$this->request->post('id', 0)), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function delete()
    {
        try {
            AigcOneClickCleanupService::deleteTask($this->tenantId, (int)$this->request->post('id', 0));
            return $this->success('删除成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}

