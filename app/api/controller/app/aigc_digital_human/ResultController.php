<?php

namespace app\api\controller\app\aigc_digital_human;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_digital_human\AigcDigitalHumanService;

class ResultController extends BaseApiController
{
    public function lists()
    {
        return $this->success('获取成功', AigcDigitalHumanService::resultLists(
            (int)$this->request->tenantId,
            $this->userId,
            0,
            (string)$this->request->get('status', '')
        ));
    }

    public function delete()
    {
        try {
            $taskId = (int)$this->request->post('task_id', 0);
            if ($taskId > 0) {
                AigcDigitalHumanService::deleteTask((int)$this->request->tenantId, $taskId, $this->userId);
                return $this->success('删除成功', [], 1, 1);
            }
            $id = (int)$this->request->post('id', 0);
            try {
                AigcDigitalHumanService::deleteResult((int)$this->request->tenantId, $id, $this->userId);
            } catch (\Exception $e) {
                AigcDigitalHumanService::deleteTask((int)$this->request->tenantId, $id, $this->userId);
            }
            return $this->success('删除成功', [], 1, 1);
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
