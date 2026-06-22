<?php

namespace app\api\controller\app\aigc_hairstyle;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_hairstyle\AigcHairstyleService;
use Exception;

class GenerateController extends BaseApiController
{
    public function estimate()
    {
        try {
            $result = AigcHairstyleService::estimate((int)$this->request->tenantId, $this->request->post());
            return $this->success('估价成功', $result);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function index()
    {
        try {
            $result = AigcHairstyleService::generate((int)$this->request->tenantId, $this->userId, $this->request->post());
            return $this->success('生成成功', $result);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
