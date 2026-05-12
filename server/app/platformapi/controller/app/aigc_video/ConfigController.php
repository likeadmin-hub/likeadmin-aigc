<?php

namespace app\platformapi\controller\app\aigc_video;

use app\common\service\app\aigc_video\AigcVideoService;
use app\platformapi\controller\BaseAdminController;

class ConfigController extends BaseAdminController
{
    public function detail()
    {
        return $this->success('获取成功', AigcVideoService::config(0));
    }

    public function setup()
    {
        AigcVideoService::saveConfig(0, $this->request->post());
        return $this->success('保存成功', [], 1, 1);
    }
}

