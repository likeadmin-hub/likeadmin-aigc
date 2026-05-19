<?php

namespace app\tenantapi\controller\app\aigc_digital_human;

use app\common\service\app\aigc_digital_human\AigcDigitalHumanService;
use app\tenantapi\controller\BaseAdminController;

class AdminController extends BaseAdminController
{
    public function stat()
    {
        return $this->success('获取成功', AigcDigitalHumanService::stat($this->tenantId));
    }

    public function quota()
    {
        if ($this->request->isPost()) {
            try {
                AigcDigitalHumanService::saveQuota($this->tenantId, $this->request->post());
                return $this->success('保存成功', [], 1, 1);
            } catch (\Exception $e) {
                return $this->fail($e->getMessage());
            }
        }
        return $this->success('获取成功', AigcDigitalHumanService::quotaLists($this->tenantId, $this->request->get()));
    }

    public function sensitiveWord()
    {
        if ($this->request->isPost()) {
            try {
                AigcDigitalHumanService::saveSensitiveWord($this->tenantId, $this->request->post());
                return $this->success('保存成功', [], 1, 1);
            } catch (\Exception $e) {
                return $this->fail($e->getMessage());
            }
        }
        return $this->success('获取成功', AigcDigitalHumanService::sensitiveWordLists($this->tenantId, $this->request->get()));
    }
}
