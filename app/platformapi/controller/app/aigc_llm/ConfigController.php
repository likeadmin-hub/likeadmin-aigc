<?php

namespace app\platformapi\controller\app\aigc_llm;

use app\common\service\app\aigc_llm\AigcLlmService;
use app\platformapi\controller\BaseAdminController;

class ConfigController extends BaseAdminController
{
    public function detail()
    {
        return $this->success('获取成功', AigcLlmService::config(0));
    }

    public function setup()
    {
        AigcLlmService::saveConfig(0, $this->request->post());
        return $this->success('保存成功', [], 1, 1);
    }
}

