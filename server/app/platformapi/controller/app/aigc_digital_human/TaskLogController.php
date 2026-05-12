<?php

namespace app\platformapi\controller\app\aigc_digital_human;

use app\common\service\app\aigc_digital_human\AigcDigitalHumanService;
use app\platformapi\controller\BaseAdminController;

class TaskLogController extends BaseAdminController
{
    public function lists()
    {
        return $this->success('获取成功', AigcDigitalHumanService::platformTaskLogs($this->request->get()));
    }

    public function detail()
    {
        try {
            $id = (int)$this->request->get('id', 0);
            return $this->success('获取成功', AigcDigitalHumanService::platformTaskLogDetail($id));
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
