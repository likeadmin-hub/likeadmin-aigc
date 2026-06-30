<?php

namespace app\api\controller\app\aigc_person_replacement;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_person_replacement\AigcPersonReplacementService;
use Exception;

class GenerateController extends BaseApiController
{
    public function estimate()
    {
        try {
            return $this->success('估价成功', AigcPersonReplacementService::estimate((int)$this->request->tenantId, $this->request->post()));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function index()
    {
        try {
            return $this->success('生成成功', AigcPersonReplacementService::generate((int)$this->request->tenantId, $this->userId, $this->request->post()));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
