<?php

namespace app\tenantapi\controller\app\aigc_image;

use app\common\service\app\PromptEnhanceModelService;
use app\common\service\app\aigc_image\ImagePromptEnhanceService;
use app\common\service\app\aigc_image\AigcImageService;
use app\tenantapi\controller\BaseAdminController;

class ConfigController extends BaseAdminController
{
    public function detail()
    {
        $data = AigcImageService::config($this->tenantId);
        $data['prompt_enhance_default_prompt'] = ImagePromptEnhanceService::instructions();
        $data['prompt_enhance_model_options'] = PromptEnhanceModelService::options($this->tenantId);
        return $this->success('获取成功', $data);
    }

    public function setup()
    {
        AigcImageService::saveConfig($this->tenantId, $this->request->post());
        return $this->success('保存成功', [], 1, 1);
    }
}

