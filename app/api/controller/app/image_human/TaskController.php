<?php

namespace app\api\controller\app\image_human;

use app\api\controller\BaseApiController;
use app\common\service\app\image_human\ImageHumanService;

class TaskController extends BaseApiController
{
    public function lists()
    {
        return $this->success('获取成功', ImageHumanService::taskLists(
            (int)$this->request->tenantId,
            $this->userId,
            $this->request->get()
        ));
    }

    public function detail()
    {
        try {
            $id = (int)$this->request->get('id', $this->request->get('task_id', 0));
            return $this->success('获取成功', ImageHumanService::taskDetail((int)$this->request->tenantId, $id, $this->userId));
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
