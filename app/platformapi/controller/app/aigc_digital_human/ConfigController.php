<?php

namespace app\platformapi\controller\app\aigc_digital_human;

use app\common\service\app\aigc_digital_human\AigcDigitalHumanService;
use app\platformapi\controller\BaseAdminController;

class ConfigController extends BaseAdminController
{
    public function detail()
    {
        return $this->success('获取成功', AigcDigitalHumanService::config(0));
    }

    public function setup()
    {
        AigcDigitalHumanService::saveConfig(0, $this->request->post());
        return $this->success('保存成功', [], 1, 1);
    }
}

