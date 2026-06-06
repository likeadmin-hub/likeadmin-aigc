<?php

namespace app\platformapi\controller\app\smart_clip;

use app\common\service\app\smart_clip\SmartClipService;
use app\platformapi\controller\BaseAdminController;

class ConfigController extends BaseAdminController
{
    public function detail()
    {
        return $this->success('获取成功', SmartClipService::config(0));
    }

    public function setup()
    {
        SmartClipService::saveConfig(0, $this->request->post());
        return $this->success('保存成功', [], 1, 1);
    }
}
