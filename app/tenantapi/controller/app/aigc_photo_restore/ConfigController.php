<?php

namespace app\tenantapi\controller\app\aigc_photo_restore;

use app\common\service\app\aigc_photo_restore\AigcPhotoRestoreService;
use app\tenantapi\controller\BaseAdminController;
use Exception;

class ConfigController extends BaseAdminController
{
    public function detail()
    {
        return $this->success('获取成功', AigcPhotoRestoreService::config($this->tenantId));
    }

    public function setup()
    {
        try {
            AigcPhotoRestoreService::saveConfig($this->tenantId, $this->request->post());
            return $this->success('保存成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}

