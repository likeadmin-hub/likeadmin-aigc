<?php

namespace app\tenantapi\controller\app\aigc_video;

use app\common\service\app\aigc_video\AigcVideoService;
use app\tenantapi\controller\BaseAdminController;

class AdminController extends BaseAdminController
{
    public function stat()
    {
        return $this->success('获取成功', AigcVideoService::stat($this->tenantId));
    }

    public function quota()
    {
        if ($this->request->isPost()) {
            try {
                AigcVideoService::saveQuota($this->tenantId, $this->request->post());
                return $this->success('保存成功', [], 1, 1);
            } catch (\Exception $e) {
                return $this->fail($e->getMessage());
            }
        }
        return $this->success('获取成功', AigcVideoService::quotaLists($this->tenantId, $this->request->get()));
    }

    public function sensitiveWord()
    {
        if ($this->request->isPost()) {
            try {
                AigcVideoService::saveSensitiveWord($this->tenantId, $this->request->post());
                return $this->success('保存成功', [], 1, 1);
            } catch (\Exception $e) {
                return $this->fail($e->getMessage());
            }
        }
        return $this->success('获取成功', AigcVideoService::sensitiveWordLists($this->tenantId, $this->request->get()));
    }
}
