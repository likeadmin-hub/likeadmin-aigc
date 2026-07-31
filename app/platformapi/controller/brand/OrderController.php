<?php

namespace app\platformapi\controller\brand;

use app\common\service\brand\TenantBrandService;
use app\platformapi\controller\BaseAdminController;

class OrderController extends BaseAdminController
{
    /** Platform-only operational recovery for an already paid order. */
    public function retry()
    {
        try {
            TenantBrandService::retryProvision((string)$this->request->post('order_sn', ''));
            return $this->success('已提交开通重试', [], 1, 1);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }
}
