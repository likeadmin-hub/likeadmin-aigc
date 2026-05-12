<?php

namespace app\tenantapi\controller\finance;

use app\common\service\recharge\RechargePackageService;
use app\tenantapi\controller\BaseAdminController;
use app\tenantapi\lists\finance\RechargePackageLists;
use Exception;

class RechargePackageController extends BaseAdminController
{
    public function lists()
    {
        return $this->dataLists(new RechargePackageLists());
    }

    public function detail()
    {
        return $this->success('获取成功', RechargePackageService::detail(
            $this->tenantId,
            (int)$this->request->get('id', 0)
        ));
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
            RechargePackageService::delete($this->tenantId, (int)$this->request->post('id', 0));
            return $this->success('操作成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    private function save()
    {
        try {
            RechargePackageService::save($this->tenantId, $this->request->post());
            return $this->success('保存成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
