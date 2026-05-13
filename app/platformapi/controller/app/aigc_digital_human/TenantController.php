<?php

namespace app\platformapi\controller\app\aigc_digital_human;

use app\common\service\app\aigc_digital_human\AigcDigitalHumanService;
use app\platformapi\controller\BaseAdminController;

class TenantController extends BaseAdminController
{
    public function stat()
    {
        return $this->success('获取成功', AigcDigitalHumanService::stat((int)$this->request->get('tenant_id', 0)));
    }
}

