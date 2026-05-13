<?php

namespace app\tenantapi\controller\app\aigc_llm;

use app\common\service\app\aigc_llm\AigcLlmService;
use app\tenantapi\controller\BaseAdminController;

class AdminSessionController extends BaseAdminController
{
    public function lists()
    {
        return $this->success('获取成功', AigcLlmService::adminSessionLists($this->tenantId, $this->request->get()));
    }

    public function detail()
    {
        try {
            return $this->success('获取成功', AigcLlmService::adminSessionDetail($this->tenantId, (int)$this->request->get('session_id', 0)));
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
