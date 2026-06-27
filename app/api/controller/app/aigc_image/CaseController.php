<?php

namespace app\api\controller\app\aigc_image;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_image\AigcImageService;
use app\common\service\case_gallery\CaseGalleryService;

class CaseController extends BaseApiController
{
    public array $notNeedLogin = ['lists'];

    public function lists()
    {
        return $this->success('获取成功', CaseGalleryService::listsByAppCodes(
            (int)$this->request->tenantId,
            [AigcImageService::APP_CODE],
            $this->request->get(),
            true
        ));
    }
}
