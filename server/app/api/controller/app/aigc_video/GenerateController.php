<?php

namespace app\api\controller\app\aigc_video;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_video\AigcVideoService;
use Exception;

class GenerateController extends BaseApiController
{
    public function estimate()
    {
        try {
            $result = AigcVideoService::estimate((int)$this->request->tenantId, $this->request->post());
            return $this->success('估价成功', $result);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function index()
    {
        try {
            $result = AigcVideoService::generate((int)$this->request->tenantId, $this->userId, $this->request->post());
            return $this->success('生成成功', $result);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
