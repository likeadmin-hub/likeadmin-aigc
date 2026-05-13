<?php

namespace app\platformapi\controller;

use app\common\service\ai\AiTaskRecordService;

class AiTaskController extends BaseAdminController
{
    public function lists()
    {
        return $this->data(AiTaskRecordService::lists($this->request->get()));
    }

    public function detail()
    {
        $id = (int)$this->request->get('id', 0);
        $detail = AiTaskRecordService::detail($id);
        if (!$detail) {
            return $this->fail('任务不存在');
        }
        return $this->success('获取成功', $detail);
    }
}
