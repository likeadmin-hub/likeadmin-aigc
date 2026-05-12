<?php

namespace app\tenantapi\controller\app\aigc_image;

use app\common\service\app\aigc_image\AigcImageService;
use app\tenantapi\controller\BaseAdminController;

class AdminController extends BaseAdminController
{
    public function stat()
    {
        return $this->success('获取成功', AigcImageService::stat($this->tenantId));
    }

    public function quota()
    {
        if ($this->request->isPost()) {
            try {
                AigcImageService::saveQuota($this->tenantId, $this->request->post());
                return $this->success('保存成功', [], 1, 1);
            } catch (\Exception $e) {
                return $this->fail($e->getMessage());
            }
        }
        return $this->success('获取成功', AigcImageService::quotaLists($this->tenantId));
    }

    public function sensitiveWord()
    {
        if ($this->request->isPost()) {
            try {
                AigcImageService::saveSensitiveWord($this->tenantId, $this->request->post());
                return $this->success('保存成功', [], 1, 1);
            } catch (\Exception $e) {
                return $this->fail($e->getMessage());
            }
        }
        return $this->success('获取成功', AigcImageService::sensitiveWordLists($this->tenantId));
    }
}
