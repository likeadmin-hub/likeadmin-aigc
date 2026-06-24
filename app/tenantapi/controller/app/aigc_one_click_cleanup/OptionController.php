<?php

namespace app\tenantapi\controller\app\aigc_one_click_cleanup;

use app\common\service\app\aigc_one_click_cleanup\AigcOneClickCleanupService;
use app\tenantapi\controller\BaseAdminController;
use Exception;

class OptionController extends BaseAdminController
{
    public function lists()
    {
        return $this->success('获取成功', AigcOneClickCleanupService::optionLists($this->tenantId));
    }

    public function save()
    {
        try {
            return $this->success('保存成功', AigcOneClickCleanupService::saveOption($this->tenantId, $this->request->post()), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function status()
    {
        try {
            AigcOneClickCleanupService::setOptionStatus($this->tenantId, $this->request->post());
            return $this->success('设置成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function delete()
    {
        try {
            AigcOneClickCleanupService::deleteOption($this->tenantId, $this->request->post());
            return $this->success('删除成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
