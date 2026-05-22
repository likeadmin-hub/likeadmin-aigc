<?php

namespace app\tenantapi\controller\app\image_human;

use app\common\service\app\image_human\ImageHumanService;
use app\tenantapi\controller\BaseAdminController;

class AdminTaskController extends BaseAdminController
{
    public function lists()
    {
        return $this->success('获取成功', ImageHumanService::taskLists(
            (int)$this->tenantId,
            0,
            $this->request->get()
        ));
    }

    public function detail()
    {
        try {
            $id = (int)$this->request->get('id', $this->request->get('task_id', 0));
            return $this->success('获取成功', ImageHumanService::taskDetail((int)$this->tenantId, $id));
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function delete()
    {
        try {
            ImageHumanService::deleteTask((int)$this->tenantId, (int)$this->request->post('id', 0));
            return $this->success('删除成功', [], 1, 1);
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
