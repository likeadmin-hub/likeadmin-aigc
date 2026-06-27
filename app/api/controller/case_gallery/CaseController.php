<?php

namespace app\api\controller\case_gallery;

use app\api\controller\BaseApiController;
use app\common\service\case_gallery\CaseGalleryService;

class CaseController extends BaseApiController
{
    public array $notNeedLogin = ['lists'];

    public function lists()
    {
        $tenantId = (int)($this->request->tenantId ?? 0);
        if ($tenantId <= 0) {
            $tenantId = (int)$this->request->get('tenant_id', 0);
        }
        return $this->success('获取成功', CaseGalleryService::lists(
            $tenantId,
            $this->request->get(),
            true
        ));
    }
}
