<?php

namespace app\tenantapi\controller\app\aigc_one_click_cleanup;

use app\common\service\app\aigc_one_click_cleanup\AigcOneClickCleanupService;
use app\tenantapi\controller\BaseAdminController;
use Exception;

class ConfigController extends BaseAdminController
{
    public function detail()
    {
        return $this->success('获取成功', AigcOneClickCleanupService::config($this->tenantId));
    }

    public function setup()
    {
        try {
            AigcOneClickCleanupService::saveConfig($this->tenantId, $this->request->post());
            return $this->success('保存成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}

