<?php

namespace app\platformapi\controller\app\aigc_llm;

use app\common\service\app\aigc_llm\AigcLlmService;
use app\platformapi\controller\BaseAdminController;

class TenantController extends BaseAdminController
{
    public function stat()
    {
        return $this->success('获取成功', AigcLlmService::tenantStat());
    }
}
