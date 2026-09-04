<?php

namespace app\api\controller\app\aigc_music_cover;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_music_cover\AigcMusicCoverService;

class ConfigController extends BaseApiController
{
    public array $notNeedLogin = ['detail'];

    public function detail()
    {
        return $this->success('获取成功', AigcMusicCoverService::config((int)$this->request->tenantId));
    }

    public function setup()
    {
        AigcMusicCoverService::saveConfig((int)$this->request->tenantId, $this->request->post());
        return $this->success('保存成功');
    }
}
