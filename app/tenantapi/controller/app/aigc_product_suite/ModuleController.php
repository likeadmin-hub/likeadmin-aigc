<?php

namespace app\tenantapi\controller\app\aigc_product_suite;

use app\common\service\app\aigc_product_suite\AigcProductSuiteService;
use app\tenantapi\controller\BaseAdminController;
use Exception;

class ModuleController extends BaseAdminController
{
    public function lists()
    {
        return $this->success('获取成功', AigcProductSuiteService::moduleLists($this->tenantId));
    }

    public function save()
    {
        try {
            return $this->success('保存成功', AigcProductSuiteService::saveModule($this->tenantId, $this->request->post()), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function status()
    {
        try {
            AigcProductSuiteService::setModuleStatus($this->tenantId, $this->request->post());
            return $this->success('设置成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function delete()
    {
        try {
            AigcProductSuiteService::deleteModule($this->tenantId, $this->request->post());
            return $this->success('删除成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
