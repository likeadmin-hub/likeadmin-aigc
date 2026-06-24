<?php

namespace app\api\controller\app\aigc_photo_restore;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_photo_restore\AigcPhotoRestoreService;

class RestoreTypeController extends BaseApiController
{
    public function lists()
    {
        return $this->success('获取成功', AigcPhotoRestoreService::restoreTypeLists(
            (int)$this->request->tenantId,
            array_merge($this->request->get(), ['only_enabled' => 1])
        ));
    }
}

