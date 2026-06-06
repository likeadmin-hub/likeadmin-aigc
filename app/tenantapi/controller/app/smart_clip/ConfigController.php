<?php

namespace app\tenantapi\controller\app\smart_clip;

use app\common\service\app\smart_clip\SmartClipService;
use app\tenantapi\controller\BaseAdminController;

class ConfigController extends BaseAdminController
{
    public function detail()
    {
        return $this->success('获取成功', SmartClipService::config($this->tenantId));
    }

    public function setup()
    {
        SmartClipService::saveConfig($this->tenantId, $this->request->post());
        return $this->success('保存成功', [], 1, 1);
    }
}
