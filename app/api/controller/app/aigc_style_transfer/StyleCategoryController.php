<?php

namespace app\api\controller\app\aigc_style_transfer;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_style_transfer\AigcStyleTransferService;

class StyleCategoryController extends BaseApiController
{
    public function lists()
    {
        return $this->success('获取成功', AigcStyleTransferService::categoryLists(
            (int)$this->request->tenantId,
            array_merge($this->request->get(), ['only_enabled' => 1])
        ));
    }
}

