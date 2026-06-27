<?php

namespace app\api\controller\app\aigc_video;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_video\AigcVideoService;
use app\common\service\case_gallery\CaseGalleryService;

class CaseController extends BaseApiController
{
    public array $notNeedLogin = ['lists'];

    public function lists()
    {
        return $this->success('获取成功', CaseGalleryService::listsByAppCodes(
            (int)$this->request->tenantId,
            [AigcVideoService::APP_CODE],
            $this->request->get(),
            true
        ));
    }
}
