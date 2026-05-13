<?php

namespace app\tenantapi\controller\app\aigc_llm;

use app\common\service\app\aigc_llm\AigcLlmService;
use app\tenantapi\controller\BaseAdminController;

class AdminController extends BaseAdminController
{
    public function stat()
    {
        return $this->success('获取成功', AigcLlmService::stat($this->tenantId));
    }

    public function sensitiveWord()
    {
        if ($this->request->isPost()) {
            try {
                AigcLlmService::saveSensitiveWord($this->tenantId, $this->request->post());
                return $this->success('保存成功', [], 1, 1);
            } catch (\Exception $e) {
                return $this->fail($e->getMessage());
            }
        }
        return $this->success('获取成功', AigcLlmService::sensitiveWordLists($this->tenantId));
    }
}
