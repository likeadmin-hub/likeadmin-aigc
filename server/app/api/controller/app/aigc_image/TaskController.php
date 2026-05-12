<?php

namespace app\api\controller\app\aigc_image;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_image\AigcImageService;

class TaskController extends BaseApiController
{
    public function lists()
    {
        return $this->success('获取成功', AigcImageService::taskLists((int)$this->request->tenantId, $this->userId));
    }

    public function detail()
    {
        try {
            $id = (int)$this->request->get('id', 0);
            return $this->success('获取成功', AigcImageService::taskDetail((int)$this->request->tenantId, $id, $this->userId));
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
