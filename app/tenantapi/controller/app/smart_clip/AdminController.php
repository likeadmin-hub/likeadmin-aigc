<?php

namespace app\tenantapi\controller\app\smart_clip;

use app\common\service\app\smart_clip\SmartClipService;
use app\tenantapi\controller\BaseAdminController;

class AdminController extends BaseAdminController
{
    public function stat()
    {
        return $this->success('获取成功', SmartClipService::stat($this->tenantId));
    }

    public function sensitiveWord()
    {
        if ($this->request->isPost()) {
            try {
                SmartClipService::saveSensitiveWord($this->tenantId, $this->request->post());
                return $this->success('保存成功', [], 1, 1);
            } catch (\Exception $e) {
                return $this->fail($e->getMessage());
            }
        }
        return $this->success('获取成功', SmartClipService::sensitiveWordLists($this->tenantId, $this->request->get()));
    }
}
