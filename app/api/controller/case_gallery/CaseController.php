<?php

namespace app\api\controller\case_gallery;

use app\api\controller\BaseApiController;
use app\common\service\case_gallery\CaseGalleryService;

class CaseController extends BaseApiController
{
    public array $notNeedLogin = ['lists'];

    public function lists()
    {
        return $this->success('获取成功', CaseGalleryService::lists(
            (int)$this->request->tenantId,
            $this->request->get(),
            true
        ));
    }
}
