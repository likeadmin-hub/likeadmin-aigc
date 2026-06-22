<?php

namespace app\tenantapi\controller\app\aigc_product_image;

use app\common\service\app\aigc_product_image\AigcProductImageService;
use app\tenantapi\controller\BaseAdminController;
use Exception;

class SceneTemplateController extends BaseAdminController
{
    public function lists()
    {
        return $this->success('获取成功', AigcProductImageService::templateLists($this->tenantId, $this->request->get()));
    }

    public function detail()
    {
        try {
            return $this->success('获取成功', AigcProductImageService::templateDetail($this->tenantId, (int)$this->request->get('id', 0)));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function save()
    {
        try {
            return $this->success('保存成功', AigcProductImageService::saveTemplate($this->tenantId, $this->request->post()), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function status()
    {
        try {
            AigcProductImageService::setTemplateStatus($this->tenantId, (int)$this->request->post('id', 0), (int)$this->request->post('status', 1));
            return $this->success('设置成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function delete()
    {
        try {
            AigcProductImageService::deleteTemplate($this->tenantId, (int)$this->request->post('id', 0));
            return $this->success('删除成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}

