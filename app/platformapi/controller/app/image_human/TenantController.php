<?php

namespace app\platformapi\controller\app\image_human;

use app\common\service\app\image_human\ImageHumanService;
use app\platformapi\controller\BaseAdminController;

class TenantController extends BaseAdminController
{
    public function stat()
    {
        return $this->success('获取成功', ImageHumanService::stat((int)$this->request->get('tenant_id', 0)));
    }
}
