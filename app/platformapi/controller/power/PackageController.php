<?php

namespace app\platformapi\controller\power;

use app\common\service\power\TenantPowerMallService;
use app\platformapi\controller\BaseAdminController;
use app\platformapi\lists\power\TenantPowerOrderLists;
use app\platformapi\lists\power\TenantPowerPackageLists;
use Exception;

class PackageController extends BaseAdminController
{
    public function lists()
    {
        return $this->dataLists(new TenantPowerPackageLists());
    }

    public function detail()
    {
        return $this->success('获取成功', TenantPowerMallService::packageDetail((int)$this->request->get('id', 0)));
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
            TenantPowerMallService::deletePackage((int)$this->request->post('id', 0));
            return $this->success('操作成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function orders()
    {
        return $this->dataLists(new TenantPowerOrderLists());
    }

    public function types()
    {
        return $this->success('获取成功', TenantPowerMallService::packageTypes());
    }

    private function save()
    {
        try {
            return $this->success('保存成功', TenantPowerMallService::savePackage($this->request->post())->toArray(), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
