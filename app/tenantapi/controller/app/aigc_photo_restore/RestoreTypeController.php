<?php

namespace app\tenantapi\controller\app\aigc_photo_restore;

use app\common\service\app\aigc_photo_restore\AigcPhotoRestoreService;
use app\tenantapi\controller\BaseAdminController;
use Exception;

class RestoreTypeController extends BaseAdminController
{
    public function lists()
    {
        return $this->success('获取成功', AigcPhotoRestoreService::restoreTypeLists($this->tenantId, $this->request->get()));
    }

    public function save()
    {
        try {
            return $this->success('保存成功', AigcPhotoRestoreService::saveRestoreType($this->tenantId, $this->request->post()), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function status()
    {
        try {
            AigcPhotoRestoreService::setRestoreTypeStatus(
                $this->tenantId,
                (string)$this->request->post('code', ''),
                (int)$this->request->post('status', 1)
            );
            return $this->success('设置成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}

