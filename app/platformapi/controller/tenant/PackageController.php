<?php

namespace app\platformapi\controller\tenant;

use app\common\service\tenant\TenantPackageService;
use app\platformapi\controller\BaseAdminController;
use app\platformapi\lists\tenant\TenantBrandOrderLists;
use app\platformapi\lists\tenant\TenantBrandQuotaOrderLists;
use app\platformapi\lists\tenant\TenantPackageLists;
use Exception;

class PackageController extends BaseAdminController
{
    public function lists()
    {
        return $this->dataLists(new TenantPackageLists());
    }

    public function detail()
    {
        return $this->success('获取成功', TenantPackageService::detail((int)$this->request->get('id', 0)));
    }

    public function add()
    {
        return $this->save();
    }

    public function edit()
    {
        return $this->save();
    }

    public function delete()
    {
        try {
            TenantPackageService::deletePackage((int)$this->request->post('id', 0));
            return $this->success('操作成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function appPlans()
    {
        return $this->success('获取成功', TenantPackageService::appPlanOptions());
    }

    public function quotaOrders()
    {
        return $this->dataLists(new TenantBrandQuotaOrderLists());
    }

    public function brandOrders()
    {
        return $this->dataLists(new TenantBrandOrderLists());
    }

    private function save()
    {
        try {
            return $this->success('保存成功', TenantPackageService::savePackage($this->request->post())->toArray(), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}

