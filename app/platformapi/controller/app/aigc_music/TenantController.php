<?php

namespace app\platformapi\controller\app\aigc_music;

use app\common\service\app\aigc_music\AigcMusicService;
use app\platformapi\controller\BaseAdminController;

class TenantController extends BaseAdminController
{
    public function stat()
    {
        return $this->success('获取成功', AigcMusicService::stat((int)$this->request->get('tenant_id', 0)));
    }
}
