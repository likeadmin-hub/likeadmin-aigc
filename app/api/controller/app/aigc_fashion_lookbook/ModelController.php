<?php

namespace app\api\controller\app\aigc_fashion_lookbook;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_fashion_lookbook\AigcFashionLookbookService;

class ModelController extends BaseApiController
{
    public array $notNeedLogin = ['lists'];

    public function lists()
    {
        return $this->success('获取成功', AigcFashionLookbookService::modelLists((int)$this->request->tenantId, true));
    }
}
