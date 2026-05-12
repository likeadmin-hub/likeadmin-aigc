<?php

namespace app\api\controller\app\aigc_video;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_video\AigcVideoService;

class ResultController extends BaseApiController
{
    public function lists()
    {
        return $this->success('获取成功', AigcVideoService::resultLists(
            (int)$this->request->tenantId,
            $this->userId,
            0,
            (string)$this->request->get('status', '')
        ));
    }

    public function delete()
    {
        try {
            $id = (int)$this->request->post('id', 0);
            $taskId = (int)$this->request->post('task_id', 0);
            if ($taskId > 0) {
                AigcVideoService::deleteTask((int)$this->request->tenantId, $taskId, $this->userId);
            } else {
                AigcVideoService::deleteResult((int)$this->request->tenantId, $id, $this->userId);
            }
            return $this->success('删除成功', [], 1, 1);
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
