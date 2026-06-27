<?php

namespace app\api\controller\app\aigc_digital_human;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_digital_human\AigcDigitalHumanService;
use app\common\service\case_gallery\CaseGalleryService;

class CaseController extends BaseApiController
{
    public array $notNeedLogin = ['lists'];

    public function lists()
    {
        return $this->success('获取成功', CaseGalleryService::listsByAppCodes(
            (int)$this->request->tenantId,
            [AigcDigitalHumanService::APP_CODE],
            $this->request->get(),
            true
        ));
    }
}
