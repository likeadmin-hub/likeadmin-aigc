<?php

namespace app\tenantapi\controller\app\aigc_music;

use app\common\service\app\aigc_music\AigcMusicService;
use app\tenantapi\controller\BaseAdminController;

class ConfigController extends BaseAdminController
{
    public function detail()
    {
        return $this->success('获取成功', AigcMusicService::config($this->tenantId));
    }

    public function setup()
    {
        try {
            AigcMusicService::saveConfig($this->tenantId, $this->request->post());
            return $this->success('保存成功', [], 1, 1);
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
