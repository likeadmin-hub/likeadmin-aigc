<?php

namespace app\tenantapi\controller\app\aigc_animate_replace;

use app\tenantapi\controller\BaseAdminController;
use app\common\service\app\aigc_animate_replace\AnimateReplaceTasks as Service;

class TaskController extends BaseAdminController
{
    public function lists()
    {
        try {
            return $this->success('操作成功', Service::lists($this->tenantId, 0, $this->request->get()));
        } catch (\Exception $error) {
            return $this->fail($error->getMessage());
        }
    }

    public function detail()
    {
        try {
            return $this->success('操作成功', Service::detail($this->tenantId, 0, (int)$this->request->get('id', 0)));
        } catch (\Exception $error) {
            return $this->fail($error->getMessage());
        }
    }
}
