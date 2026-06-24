<?php

namespace app\api\controller\app\aigc_one_click_cleanup;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_one_click_cleanup\AigcOneClickCleanupService;
use Exception;

class ResultController extends BaseApiController
{
    public function lists()
    {
        return $this->success('获取成功', AigcOneClickCleanupService::resultLists(
            (int)$this->request->tenantId,
            $this->userId,
            $this->request->get()
        ));
    }

    public function delete()
    {
        try {
            AigcOneClickCleanupService::deleteResult(
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

