<?php

namespace app\api\controller\app\aigc_background_removal;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_background_removal\AigcBackgroundRemovalService;
use Exception;

class ResultController extends BaseApiController
{
    public function lists()
    {
        return $this->success('获取成功', AigcBackgroundRemovalService::resultLists(
            (int)$this->request->tenantId,
            $this->userId,
            $this->request->get()
        ));
    }

    public function delete()
    {
        try {
            AigcBackgroundRemovalService::deleteResult(
                (int)$this->request->tenantId,
                (int)$this->request->post('id', 0),
                $this->userId
            );
            return $this->success('删除成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}

