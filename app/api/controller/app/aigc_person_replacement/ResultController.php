<?php

namespace app\api\controller\app\aigc_person_replacement;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_person_replacement\AigcPersonReplacementService;
use Exception;

class ResultController extends BaseApiController
{
    public function delete()
    {
        try {
            AigcPersonReplacementService::deleteResult((int)$this->request->tenantId, (int)$this->request->post('id', 0), $this->userId);
            return $this->success('删除成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
