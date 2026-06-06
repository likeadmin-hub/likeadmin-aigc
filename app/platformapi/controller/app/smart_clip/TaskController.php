<?php

namespace app\platformapi\controller\app\smart_clip;

use app\common\service\app\smart_clip\SmartClipService;
use app\platformapi\controller\BaseAdminController;

class TaskController extends BaseAdminController
{
    public function lists()
    {
        return $this->success('获取成功', SmartClipService::platformTaskLists($this->request->get()));
    }

    public function detail()
    {
        try {
            $id = (int)$this->request->get('id', 0);
            return $this->success('获取成功', SmartClipService::platformTaskDetail($id));
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
