<?php

namespace app\api\controller\app\aigc_video;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_video\AigcVideoService;

class ConfigController extends BaseApiController
{
    public array $notNeedLogin = ['detail'];

    public function detail()
    {
        $data = AigcVideoService::config((int)$this->request->tenantId);
        unset($data['config_json']['prompt_enhance_system_prompt'], $data['config_json']['video_effects']);
        $data['video_effects'] = \app\common\service\app\aigc_video\VideoEffectService::publicCatalog($data['video_effects'] ?? []);
        return $this->success('获取成功', $data);
    }
}

