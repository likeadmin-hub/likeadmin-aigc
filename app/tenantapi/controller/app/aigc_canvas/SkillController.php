<?php

namespace app\tenantapi\controller\app\aigc_canvas;

use app\common\service\app\aigc_canvas\AigcCanvasSkillService;
use app\tenantapi\controller\BaseAdminController;
use Exception;

class SkillController extends BaseAdminController
{
    public function lists()
    {
        try {
            AigcCanvasSkillService::seedBuiltinSkills($this->tenantId);
            return $this->success('success', AigcCanvasSkillService::lists($this->tenantId, $this->request->get()));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function detail()
    {
        try {
            return $this->success('success', AigcCanvasSkillService::detail($this->tenantId, (int)$this->request->get('id', 0)));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function create()
    {
        try {
            return $this->success('created', AigcCanvasSkillService::create($this->tenantId, $this->adminId, $this->request->post()), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function update()
    {
        try {
            return $this->success('updated', AigcCanvasSkillService::update($this->tenantId, $this->request->post()), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function status()
    {
        try {
            return $this->success('updated', AigcCanvasSkillService::status($this->tenantId, $this->request->post()), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function delete()
    {
        try {
            AigcCanvasSkillService::delete($this->tenantId, (int)$this->request->post('id', 0));
            return $this->success('deleted', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
