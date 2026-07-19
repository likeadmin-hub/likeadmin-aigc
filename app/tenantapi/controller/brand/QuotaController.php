<?php

namespace app\tenantapi\controller\brand;

use app\common\enum\user\UserTerminalEnum;
use app\common\service\brand\TenantBrandService;
use app\tenantapi\controller\BaseAdminController;
use app\tenantapi\lists\brand\TenantBrandQuotaOrderLists;
use Exception;

class QuotaController extends BaseAdminController
{
    public function packages()
    {
        return $this->success('获取成功', TenantBrandService::packageRows((int)$this->adminInfo['tenant_id']));
    }

    public function createOrder()
    {
        try {
            $result = TenantBrandService::createQuotaOrder(
                (int)$this->adminInfo['tenant_id'],
                $this->adminId,
                UserTerminalEnum::PC,
                (int)$this->request->post('package_id', 0),
                (int)$this->request->post('quantity', 1)
            );
            return $this->success('创建成功', $result, 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function orders()
    {
        return $this->dataLists(new TenantBrandQuotaOrderLists());
    }
}

