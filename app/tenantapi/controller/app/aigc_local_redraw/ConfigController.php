<?php

namespace app\tenantapi\controller\app\aigc_local_redraw;

use app\common\service\app\aigc_local_redraw\AigcLocalRedrawService;
use app\tenantapi\controller\BaseAdminController;
use Exception;

class ConfigController extends BaseAdminController
{
    public function detail()
    {
        return $this->success('获取成功', AigcLocalRedrawService::config($this->tenantId));
    }

    public function setup()
    {
        try {
            AigcLocalRedrawService::saveConfig($this->tenantId, $this->request->post());
            return $this->success('保存成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}

