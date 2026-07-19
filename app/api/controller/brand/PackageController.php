<?php

namespace app\api\controller\brand;

use app\api\controller\BaseApiController;
use app\common\service\brand\TenantBrandService;

class PackageController extends BaseApiController
{
    public array $notNeedLogin = ['lists'];

    public function lists()
    {
        return $this->success('获取成功', TenantBrandService::packageRows((int)$this->request->tenantId, true));
    }
}

