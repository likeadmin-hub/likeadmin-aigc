<?php

namespace app\platformapi\controller\app\aigc_hairstyle;

use app\common\service\app\aigc_hairstyle\AigcHairstyleService;
use app\platformapi\controller\BaseAdminController;

class TenantController extends BaseAdminController
{
    public function stat()
    {
        return $this->success('获取成功', AigcHairstyleService::stat((int)$this->request->get('tenant_id', 0)));
    }

    public function lists()
    {
        return $this->success('获取成功', AigcHairstyleService::tenantUsageLists($this->request->get()));
    }
}
