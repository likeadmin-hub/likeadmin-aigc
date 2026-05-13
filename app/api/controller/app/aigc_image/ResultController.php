<?php

namespace app\api\controller\app\aigc_image;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_image\AigcImageService;

class ResultController extends BaseApiController
{
    public function lists()
    {
        return $this->success('获取成功', AigcImageService::resultLists(
            (int)$this->request->tenantId,
            $this->userId,
            0,
            (string)$this->request->get('status', '')
        ));
    }

    public function delete()
    {
        try {
            $id = (int)$this->request->post('id', 0);
            AigcImageService::deleteTask((int)$this->request->tenantId, $id, $this->userId);
            return $this->success('删除成功', [], 1, 1);
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
