<?php

namespace app\tenantapi\controller\app\aigc_product_promo_video;

use app\common\service\app\aigc_product_promo_video\AigcProductPromoVideoService;
use app\tenantapi\controller\BaseAdminController;
use Exception;

class TypeController extends BaseAdminController
{
    public function lists()
    {
        return $this->success('获取成功', AigcProductPromoVideoService::typeLists($this->tenantId));
    }

    public function save()
    {
        try {
            return $this->success('保存成功', AigcProductPromoVideoService::saveType($this->tenantId, $this->request->post()), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function status()
    {
        try {
            AigcProductPromoVideoService::setTypeStatus($this->tenantId, $this->request->post());
            return $this->success('设置成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function delete()
    {
        try {
            AigcProductPromoVideoService::deleteType($this->tenantId, $this->request->post());
            return $this->success('删除成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
