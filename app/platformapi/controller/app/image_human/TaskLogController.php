<?php

namespace app\platformapi\controller\app\image_human;

use app\common\service\app\image_human\ImageHumanService;
use app\platformapi\controller\BaseAdminController;

class TaskLogController extends BaseAdminController
{
    public function lists()
    {
        return $this->success('获取成功', ImageHumanService::platformTaskLogs($this->request->get()));
    }

    public function detail()
    {
        try {
            $id = (int)$this->request->get('id', 0);
            return $this->success('获取成功', ImageHumanService::platformTaskLogDetail($id));
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
