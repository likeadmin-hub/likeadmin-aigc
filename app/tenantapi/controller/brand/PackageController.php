<?php

namespace app\tenantapi\controller\brand;

use app\common\service\brand\TenantBrandService;
use app\tenantapi\controller\BaseAdminController;
use Exception;

class PackageController extends BaseAdminController
{
    public function lists()
    {
        return $this->success('获取成功', TenantBrandService::packageRows((int)$this->adminInfo['tenant_id']));
    }

    public function savePrice()
    {
        try {
            TenantBrandService::savePrice((int)$this->adminInfo['tenant_id'], $this->request->post());
            return $this->success('保存成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}

