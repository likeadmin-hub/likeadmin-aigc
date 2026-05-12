<?php

namespace app\tenantapi\controller\app\aigc_canvas;

use app\common\service\app\aigc_canvas\AigcCanvasService;
use app\tenantapi\controller\BaseAdminController;
use Exception;

class AdminProjectController extends BaseAdminController
{
    public function lists()
    {
        return $this->success('获取成功', AigcCanvasService::projectLists($this->tenantId, 0, $this->request->get()));
    }

    public function delete()
    {
        try {
            AigcCanvasService::adminDeleteProject($this->tenantId, (int)$this->request->post('id', 0));
            return $this->success('删除成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function clear()
    {
        $count = AigcCanvasService::clearProjects($this->tenantId, (int)$this->request->post('user_id', 0));
        return $this->success('清理成功', ['count' => $count], 1, 1);
    }
}
