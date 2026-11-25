<?php

namespace app\tenantapi\controller\app\aigc_animate_replace;

use app\tenantapi\controller\BaseAdminController;
use app\common\service\app\aigc_animate_replace\AnimateReplaceService as Service;

class ConfigController extends BaseAdminController
{
    public function detail()
    {
        return $this->success('获取成功', Service::config($this->tenantId));
    }

    public function setup()
    {
        try {
            Service::setup($this->tenantId, $this->request->post());
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }
        return $this->success('保存成功');
    }
}
