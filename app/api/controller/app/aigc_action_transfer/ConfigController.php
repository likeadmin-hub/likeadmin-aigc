<?php

namespace app\api\controller\app\aigc_action_transfer;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_action_transfer\AigcActionTransferService;

class ConfigController extends BaseApiController
{
    public array $notNeedLogin = ['detail'];

    public function detail()
    {
        return $this->success('获取成功', AigcActionTransferService::config((int)$this->request->tenantId));
    }
}
