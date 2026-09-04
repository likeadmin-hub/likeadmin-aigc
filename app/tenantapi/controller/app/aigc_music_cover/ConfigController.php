<?php

namespace app\tenantapi\controller\app\aigc_music_cover;

use app\common\service\app\aigc_music_cover\AigcMusicCoverService;
use app\tenantapi\controller\BaseAdminController;
use Exception;

class ConfigController extends BaseAdminController
{
    public function detail()
    {
        return $this->success('获取成功', AigcMusicCoverService::config($this->tenantId));
    }

    public function setup()
    {
        try {
            AigcMusicCoverService::saveConfig($this->tenantId, $this->request->post());
            return $this->success('保存成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
