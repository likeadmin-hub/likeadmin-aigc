<?php

namespace app\api\controller\app\aigc_action_transfer;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_action_transfer\AigcActionTransferService;
use Exception;

class GenerateController extends BaseApiController
{
    public function estimate()
    {
        try {
            return $this->success('估价成功', AigcActionTransferService::estimate((int)$this->request->tenantId, $this->request->post()));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function index()
    {
        try {
            return $this->success('生成成功', AigcActionTransferService::generate((int)$this->request->tenantId, $this->userId, $this->request->post()));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
