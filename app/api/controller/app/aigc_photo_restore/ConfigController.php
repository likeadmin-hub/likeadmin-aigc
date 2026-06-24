<?php

namespace app\api\controller\app\aigc_photo_restore;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_photo_restore\AigcPhotoRestoreService;

class ConfigController extends BaseApiController
{
    public function detail()
    {
        return $this->success('获取成功', AigcPhotoRestoreService::config((int)$this->request->tenantId));
    }
}

