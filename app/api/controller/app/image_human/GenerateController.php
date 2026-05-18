<?php

namespace app\api\controller\app\image_human;

use app\api\controller\BaseApiController;
use app\common\service\app\image_human\ImageHumanService;
use Exception;

class GenerateController extends BaseApiController
{
    public function estimate()
    {
        try {
            return $this->success('估价成功', ImageHumanService::estimate((int)$this->request->tenantId, $this->request->post(), $this->userId));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function submit()
    {
        try {
            return $this->success('提交成功', ImageHumanService::submit((int)$this->request->tenantId, $this->userId, $this->request->post()));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
