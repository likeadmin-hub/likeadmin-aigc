<?php

namespace app\api\controller\app\aigc_fitting;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_fitting\AigcFittingService;
use Exception;

class ResultController extends BaseApiController
{
    public function lists()
    {
        return $this->success('获取成功', AigcFittingService::resultLists(
            (int)$this->request->tenantId,
            $this->userId,
            $this->request->get()
        ));
    }

    public function delete()
    {
        try {
            AigcFittingService::deleteResult(
                (int)$this->request->tenantId,
                (int)$this->request->post('id', 0),
                $this->userId,
                (int)$this->request->post('image_task_id', 0)
            );
            return $this->success('删除成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
