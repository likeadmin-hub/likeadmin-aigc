<?php

namespace app\api\controller\app\aigc_llm;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_llm\AigcLlmService;
use Exception;

class MessageController extends BaseApiController
{
    public function lists()
    {
        try {
            return $this->success('获取成功', AigcLlmService::messageLists((int)$this->request->tenantId, $this->userId, (int)$this->request->get('session_id', 0)));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
