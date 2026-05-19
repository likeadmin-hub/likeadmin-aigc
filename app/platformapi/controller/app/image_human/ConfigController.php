<?php

namespace app\platformapi\controller\app\image_human;

use app\common\service\app\image_human\ImageHumanService;
use app\platformapi\controller\BaseAdminController;

class ConfigController extends BaseAdminController
{
    public function detail()
    {
        return $this->success('获取成功', ImageHumanService::config(0));
    }

    public function setup()
    {
        ImageHumanService::saveConfig(0, $this->request->post());
        return $this->success('保存成功', [], 1, 1);
    }
}
