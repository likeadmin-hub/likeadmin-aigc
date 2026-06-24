<?php

namespace app\tenantapi\controller\app\aigc_product_multi_angle;

use app\common\service\app\aigc_product_multi_angle\AigcProductMultiAngleService;
use app\tenantapi\controller\BaseAdminController;
use Exception;

class ViewController extends BaseAdminController
{
    public function lists()
    {
        return $this->success('获取成功', AigcProductMultiAngleService::viewLists($this->tenantId));
    }

    public function save()
    {
        try {
            return $this->success('保存成功', AigcProductMultiAngleService::saveView($this->tenantId, $this->request->post()), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function status()
    {
        try {
            AigcProductMultiAngleService::setViewStatus($this->tenantId, $this->request->post());
            return $this->success('设置成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function delete()
    {
        try {
            AigcProductMultiAngleService::deleteView($this->tenantId, $this->request->post());
            return $this->success('删除成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
