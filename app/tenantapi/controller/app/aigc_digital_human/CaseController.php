<?php

namespace app\tenantapi\controller\app\aigc_digital_human;

use app\common\service\app\aigc_digital_human\AigcDigitalHumanService;
use app\common\service\case_gallery\CaseGalleryService;
use app\tenantapi\controller\BaseAdminController;
use Exception;

class CaseController extends BaseAdminController
{
    public function lists()
    {
        return $this->success('获取成功', CaseGalleryService::listsByAppCodes($this->tenantId, [AigcDigitalHumanService::APP_CODE], $this->request->get()));
    }

    public function detail()
    {
        try {
            return $this->success('获取成功', CaseGalleryService::detailByAppCodes(
                $this->tenantId,
                [AigcDigitalHumanService::APP_CODE],
                (int)$this->request->get('id', 0)
            ));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function save()
    {
        try {
            return $this->success('保存成功', CaseGalleryService::saveByAppCodes(
                $this->tenantId,
                [AigcDigitalHumanService::APP_CODE],
                $this->request->post(),
                AigcDigitalHumanService::APP_CODE
            ), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function fromTask()
    {
        try {
            return $this->success('设置成功', AigcDigitalHumanService::saveCaseFromTask(
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
            CaseGalleryService::setStatusByAppCodes(
                $this->tenantId,
                [AigcDigitalHumanService::APP_CODE],
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
            CaseGalleryService::deleteByAppCodes($this->tenantId, [AigcDigitalHumanService::APP_CODE], (int)$this->request->post('id', 0));
            return $this->success('删除成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
