<?php

namespace app\api\controller\app\aigc_product_suite;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_product_suite\AigcProductSuiteService;

class ModuleController extends BaseApiController
{
    public array $notNeedLogin = ['lists'];

    public function lists()
    {
        return $this->success('获取成功', AigcProductSuiteService::moduleLists((int)$this->request->tenantId, true));
    }
}
