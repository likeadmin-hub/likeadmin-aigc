<?php

namespace app\platformapi\controller\app\aigc_image;

use app\common\service\app\aigc_image\AigcImageService;
use app\platformapi\controller\BaseAdminController;

class ConfigController extends BaseAdminController
{
    public function detail()
    {
        return $this->success('获取成功', AigcImageService::config(0));
    }

    public function setup()
    {
        AigcImageService::saveConfig(0, $this->request->post());
        return $this->success('保存成功', [], 1, 1);
    }
}

