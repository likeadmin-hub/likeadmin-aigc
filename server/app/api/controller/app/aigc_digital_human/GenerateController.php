<?php

namespace app\api\controller\app\aigc_digital_human;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_digital_human\AigcDigitalHumanService;
use Exception;

class GenerateController extends BaseApiController
{
    public function estimate()
    {
        try {
            $result = AigcDigitalHumanService::estimate((int)$this->request->tenantId, $this->request->post());
            return $this->success('估价成功', $result);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function assistScript()
    {
        try {
            return $this->success('生成成功', AigcDigitalHumanService::assistScript(
                (int)$this->request->tenantId,
                $this->userId,
                $this->request->post()
            ));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function index()
    {
        try {
            $result = AigcDigitalHumanService::generate((int)$this->request->tenantId, $this->userId, $this->request->post());
            return $this->success('生成成功', $result);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
