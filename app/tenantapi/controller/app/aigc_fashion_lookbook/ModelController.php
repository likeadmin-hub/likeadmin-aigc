<?php

namespace app\tenantapi\controller\app\aigc_fashion_lookbook;

use app\common\service\app\aigc_fashion_lookbook\AigcFashionLookbookService;
use app\tenantapi\controller\BaseAdminController;
use Exception;

class ModelController extends BaseAdminController
{
    public function lists()
    {
        return $this->success('获取成功', AigcFashionLookbookService::modelLists($this->tenantId));
    }

    public function save()
    {
        try {
            return $this->success('保存成功', AigcFashionLookbookService::saveModel($this->tenantId, $this->request->post()), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function status()
    {
        try {
            AigcFashionLookbookService::setModelStatus($this->tenantId, $this->request->post());
            return $this->success('设置成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function delete()
    {
        try {
            AigcFashionLookbookService::deleteModel($this->tenantId, $this->request->post());
            return $this->success('删除成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
