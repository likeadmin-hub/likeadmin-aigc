<?php

namespace app\tenantapi\controller\case_gallery;

use app\common\service\case_gallery\CaseGalleryService;
use app\tenantapi\controller\BaseAdminController;
use Exception;

class CaseController extends BaseAdminController
{
    public function lists()
    {
        return $this->success('获取成功', CaseGalleryService::lists($this->tenantId, $this->request->get()));
    }

    public function apps()
    {
        return $this->success('获取成功', CaseGalleryService::appOptions($this->tenantId));
    }

    public function detail()
    {
        try {
            return $this->success('获取成功', CaseGalleryService::detail(
                $this->tenantId,
                (int)$this->request->get('id', 0)
            ));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function save()
    {
        try {
            return $this->success('保存成功', CaseGalleryService::save(
                $this->tenantId,
                $this->request->post()
            ), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function fromTask()
    {
        try {
            return $this->success('设置成功', CaseGalleryService::fromTask(
                $this->tenantId,
                (int)$this->request->post('task_id', 0),
                $this->request->post()
            ), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function status()
    {
        try {
            CaseGalleryService::setStatus(
                $this->tenantId,
                (int)$this->request->post('id', 0),
                (int)$this->request->post('status', 1)
            );
            return $this->success('设置成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function delete()
    {
        try {
            CaseGalleryService::delete($this->tenantId, (int)$this->request->post('id', 0));
            return $this->success('删除成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
