<?php

namespace app\tenantapi\controller\app\aigc_music;

use app\common\service\app\aigc_music\AigcMusicService;
use app\tenantapi\controller\BaseAdminController;

class AdminController extends BaseAdminController
{
    public function stat()
    {
        return $this->success('获取成功', AigcMusicService::stat($this->tenantId));
    }
}
