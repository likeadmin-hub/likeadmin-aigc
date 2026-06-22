<?php

namespace app\api\controller\app\aigc_style_transfer;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_style_transfer\AigcStyleTransferService;

class ConfigController extends BaseApiController
{
    public function detail()
    {
        return $this->success('获取成功', AigcStyleTransferService::config((int)$this->request->tenantId));
    }
}

