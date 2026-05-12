<?php

namespace app\tenantapi\controller\app\aigc_digital_human;

use app\common\service\app\aigc_digital_human\AigcDigitalHumanService;
use app\tenantapi\controller\BaseAdminController;

class AdminTaskController extends BaseAdminController
{
    public function lists()
    {
        return $this->success('获取成功', AigcDigitalHumanService::taskLists($this->tenantId, 0, $this->request->get()));
    }

    public function detail()
    {
        try {
            $id = (int)$this->request->get('id', 0);
            return $this->success('获取成功', AigcDigitalHumanService::taskDetail($this->tenantId, $id));
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function retry()
    {
        try {
            $id = (int)$this->request->post('id', 0);
            return $this->success('重试成功', AigcDigitalHumanService::retryTask($this->tenantId, $id), 1, 1);
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function delete()
    {
        try {
            $id = (int)$this->request->post('id', 0);
            AigcDigitalHumanService::deleteTask($this->tenantId, $id);
            return $this->success('删除成功', [], 1, 1);
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
