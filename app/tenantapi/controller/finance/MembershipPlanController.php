<?php

namespace app\tenantapi\controller\finance;

use app\common\service\membership\MembershipService;
use app\tenantapi\controller\BaseAdminController;
use app\tenantapi\lists\finance\MembershipPlanLists;
use Exception;

class MembershipPlanController extends BaseAdminController
{
    public function lists()
    {
        return $this->dataLists(new MembershipPlanLists());
    }

    public function detail()
    {
        $id = (int)$this->request->get('id', 0);
        return $this->success('获取成功', MembershipService::detail($this->tenantId, $id));
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
            MembershipService::deletePlan($this->tenantId, (int)$this->request->post('id', 0));
            return $this->success('操作成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function apps()
    {
        return $this->success('获取成功', MembershipService::tenantOpenedApps($this->tenantId));
    }

    private function save()
    {
        try {
            MembershipService::savePlan($this->tenantId, $this->request->post());
            return $this->success('保存成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
