<?php

namespace app\tenantapi\controller\app\aigc_video;

use app\common\service\app\PromptEnhanceModelService;
use app\common\service\app\aigc_video\VideoPromptEnhanceService;
use app\common\service\app\aigc_video\AigcVideoService;
use app\tenantapi\controller\BaseAdminController;

class ConfigController extends BaseAdminController
{
    public function detail()
    {
        $data = AigcVideoService::config($this->tenantId);
        $data['prompt_enhance_default_prompt'] = VideoPromptEnhanceService::instructions();
        $data['prompt_enhance_model_options'] = PromptEnhanceModelService::options($this->tenantId);
        return $this->success('获取成功', $data);
    }

    public function setup()
    {
        AigcVideoService::saveConfig($this->tenantId, $this->request->post());
        return $this->success('保存成功', [], 1, 1);
    }
}

