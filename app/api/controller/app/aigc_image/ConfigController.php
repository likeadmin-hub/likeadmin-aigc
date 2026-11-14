<?php

namespace app\api\controller\app\aigc_image;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_image\AigcImageService;

class ConfigController extends BaseApiController
{
    public array $notNeedLogin = ['detail'];

    public function detail()
    {
        $data = AigcImageService::config((int)$this->request->tenantId);
        unset($data['config_json']['prompt_enhance_system_prompt']);
        if ($this->request->get('catalog_format', '') === 'channels') {
            unset($data['option_config']['models'], $data['option_config']['applications']);
        }
        return $this->success('获取成功', $data);
    }
}

