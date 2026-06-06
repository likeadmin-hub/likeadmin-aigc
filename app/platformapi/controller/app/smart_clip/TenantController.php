<?php

namespace app\platformapi\controller\app\smart_clip;

use app\common\service\app\smart_clip\SmartClipService;
use app\platformapi\controller\BaseAdminController;

class TenantController extends BaseAdminController
{
    public function stat()
    {
        return $this->success('获取成功', SmartClipService::stat((int)$this->request->get('tenant_id', 0)));
    }
}
