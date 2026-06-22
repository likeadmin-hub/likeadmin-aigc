<?php

namespace app\tenantapi\controller\app\aigc_style_transfer;

use app\common\service\app\aigc_style_transfer\AigcStyleTransferService;
use app\tenantapi\controller\BaseAdminController;
use Exception;

class StyleTemplateController extends BaseAdminController
{
    public function lists()
    {
        return $this->success('获取成功', AigcStyleTransferService::templateLists($this->tenantId, $this->request->get()));
    }

    public function detail()
    {
        try {
            return $this->success('获取成功', AigcStyleTransferService::templateDetail($this->tenantId, (int)$this->request->get('id', 0)));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function save()
    {
        try {
            return $this->success('保存成功', AigcStyleTransferService::saveTemplate($this->tenantId, $this->request->post()), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function status()
    {
        try {
            AigcStyleTransferService::setTemplateStatus($this->tenantId, (int)$this->request->post('id', 0), (int)$this->request->post('status', 1));
            return $this->success('设置成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function delete()
    {
        try {
            AigcStyleTransferService::deleteTemplate($this->tenantId, (int)$this->request->post('id', 0));
            return $this->success('删除成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}

